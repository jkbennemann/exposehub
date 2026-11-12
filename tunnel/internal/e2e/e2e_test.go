// Package e2e runs a real server and real agents in one process and sends
// traffic through them over loopback connections.
package e2e

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"
	"github.com/hashicorp/yamux"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
	"github.com/jkbennemann/exposehub/tunnel/internal/transport"
)

const (
	agentHost    = "connect.tunnel.test"
	tunnelDomain = "tunnel.test"
)

var quiet = slog.New(slog.NewTextHandler(io.Discard, nil))

// harness is a running tunnel server plus a client that reaches it under any
// hostname, the way a wildcard DNS record would.
type harness struct {
	addr    string
	visitor *http.Client
}

func newHarness(tb testing.TB, configure ...func(*server.Config)) *harness {
	tb.Helper()
	tokens, err := server.ParseStaticTokens("alice:alice-token,alice:alice-second-token,bob:bob-token")
	if err != nil {
		tb.Fatal(err)
	}
	h, _ := newHarnessWith(tb, tokens, configure...)
	return h
}

// newHarnessWith starts a server that authenticates agents with auth.
func newHarnessWith(tb testing.TB, auth server.Authenticator, configure ...func(*server.Config)) (*harness, *server.Server) {
	tb.Helper()
	cfg := server.Config{
		AgentHost:     agentHost,
		TunnelDomains: []string{tunnelDomain},
		PublicPort:    "80",
	}
	for _, fn := range configure {
		fn(&cfg)
	}
	srv, err := server.New(cfg, auth, quiet)
	if err != nil {
		tb.Fatal(err)
	}
	ts := httptest.NewServer(srv)
	tb.Cleanup(func() {
		srv.Close()
		ts.Close()
	})
	addr := ts.Listener.Addr().String()
	return &harness{addr: addr, visitor: &http.Client{Transport: pinned(addr, nil)}}, srv
}

// pinned returns a transport that connects to addr whatever the URL says.
func pinned(addr string, track *connTracker) *http.Transport {
	return &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			c, err := (&net.Dialer{}).DialContext(ctx, "tcp", addr)
			if err == nil && track != nil {
				track.add(c)
			}
			return c, err
		},
		DisableCompression:  true,
		MaxIdleConnsPerHost: 64,
		// Stay below the listen backlog (128 on macOS): more simultaneous
		// connects than that are reset by the kernel before the server sees them.
		MaxConnsPerHost: 100,
	}
}

type connTracker struct {
	mu    sync.Mutex
	conns []net.Conn
}

func (t *connTracker) add(c net.Conn) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.conns = append(t.conns, c)
}

// drop cuts every connection made so far, like a network failure would.
func (t *connTracker) drop() {
	t.mu.Lock()
	defer t.mu.Unlock()
	for _, c := range t.conns {
		c.Close()
	}
	t.conns = nil
}

type runningAgent struct {
	tunnels []proto.TunnelInfo
	// notices collects what the agent told the developer through OnNotice.
	notices chan string
	conns   *connTracker
	cancel  context.CancelFunc
	done    chan struct{}
	err     error
}

// stop shuts the agent down and waits until it has disconnected.
func (a *runningAgent) stop() {
	a.cancel()
	<-a.done
}

func (a *runningAgent) host() string { return a.tunnels[0].ID }

// wait returns what agent.Run returned, failing the test if it keeps running.
func (a *runningAgent) wait(tb testing.TB) error {
	tb.Helper()
	select {
	case <-a.done:
		return a.err
	case <-time.After(5 * time.Second):
		tb.Fatal("agent did not stop")
		return nil
	}
}

// launch starts an agent without waiting for it to connect.
func (h *harness) launch(tb testing.TB, token string, tunnels ...agent.Tunnel) (*runningAgent, <-chan []proto.TunnelInfo) {
	tb.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	a := &runningAgent{conns: &connTracker{}, cancel: cancel, done: make(chan struct{}), notices: make(chan string, 16)}
	connected := make(chan []proto.TunnelInfo, 16)
	go func() {
		defer close(a.done)
		a.err = agent.Run(ctx, agent.Config{
			Server:     "ws://" + agentHost,
			Token:      token,
			Tunnels:    tunnels,
			Version:    "test",
			Logger:     quiet,
			HTTPClient: &http.Client{Transport: pinned(h.addr, a.conns)},
			OnConnect: func(infos []proto.TunnelInfo) {
				select {
				case connected <- infos:
				default:
				}
			},
			OnNotice: func(_, text string) {
				select {
				case a.notices <- text:
				default:
				}
			},
		})
	}()
	tb.Cleanup(a.stop)
	return a, connected
}

// start launches an agent and waits until its tunnels are open.
func (h *harness) start(tb testing.TB, token string, tunnels ...agent.Tunnel) *runningAgent {
	tb.Helper()
	a, connected := h.launch(tb, token, tunnels...)
	select {
	case a.tunnels = <-connected:
	case <-a.done:
		tb.Fatalf("agent stopped before connecting: %v", a.err)
	case <-time.After(5 * time.Second):
		tb.Fatal("agent did not connect")
	}
	return a
}

// startWhenFree is start for a name another agent held until a moment ago:
// it tries again while the server still answers that the name is in use.
func (h *harness) startWhenFree(tb testing.TB, token string, tunnels ...agent.Tunnel) *runningAgent {
	tb.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for {
		a, connected := h.launch(tb, token, tunnels...)
		select {
		case a.tunnels = <-connected:
			return a
		case <-a.done:
			if time.Now().After(deadline) || !strings.Contains(fmt.Sprint(a.err), "in use") {
				tb.Fatalf("agent stopped before connecting: %v", a.err)
			}
			time.Sleep(10 * time.Millisecond)
		case <-time.After(5 * time.Second):
			tb.Fatal("agent did not connect")
		}
	}
}

// upstream starts a local service and returns a tunnel pointing at it.
func upstream(tb testing.TB, name string, h http.Handler) agent.Tunnel {
	tb.Helper()
	ts := httptest.NewServer(h)
	tb.Cleanup(ts.Close)
	u, err := url.Parse(ts.URL)
	if err != nil {
		tb.Fatal(err)
	}
	return agent.Tunnel{Name: name, Upstream: u}
}

func (h *harness) get(tb testing.TB, host, path string) (int, string) {
	tb.Helper()
	resp, err := h.visitor.Get("http://" + host + path)
	if err != nil {
		tb.Fatal(err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		tb.Fatal(err)
	}
	return resp.StatusCode, string(body)
}

// dialAgent connects to the agent endpoint the way an agent does and returns
// the HTTP status the server answered, 101 when it accepted the connection.
func dialAgent(tb testing.TB, h *harness, token string) (*websocket.Conn, int) {
	tb.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	ws, resp, err := websocket.Dial(ctx, "ws://"+agentHost+proto.AgentPath, &websocket.DialOptions{
		HTTPClient: &http.Client{Transport: pinned(h.addr, nil)},
		HTTPHeader: http.Header{"Authorization": {"Bearer " + token}},
	})
	if resp == nil {
		tb.Fatalf("dialing the agent endpoint: %v", err)
	}
	return ws, resp.StatusCode
}

// rawAgent plays an agent that the test controls frame by frame: it opens
// the session and the control stream, and sends nothing yet.
func rawAgent(tb testing.TB, h *harness, token string) (*yamux.Session, *yamux.Stream) {
	tb.Helper()
	ws, status := dialAgent(tb, h, token)
	if ws == nil {
		tb.Fatalf("the agent endpoint answered %d", status)
	}
	ctx, cancel := context.WithCancel(context.Background())
	mux, err := yamux.Client(transport.NetConn(ctx, ws), transport.MuxConfig(quiet))
	if err != nil {
		cancel()
		tb.Fatal(err)
	}
	tb.Cleanup(func() {
		mux.Close()
		cancel()
	})
	ctrl, err := mux.OpenStream()
	if err != nil {
		tb.Fatal(err)
	}
	return mux, ctrl
}

// handshake sends hello on ctrl and returns the server's Welcome.
func handshake(tb testing.TB, ctrl *yamux.Stream, hello proto.Hello) proto.Welcome {
	tb.Helper()
	hello.Version = proto.Version
	if err := proto.WriteFrame(ctrl, hello); err != nil {
		tb.Fatal(err)
	}
	var welcome proto.Welcome
	_ = ctrl.SetReadDeadline(time.Now().Add(5 * time.Second))
	if err := proto.ReadFrame(ctrl, &welcome); err != nil {
		tb.Fatalf("reading the welcome: %v", err)
	}
	_ = ctrl.SetReadDeadline(time.Time{})
	return welcome
}

func echoPath() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprint(w, r.URL.RequestURI())
	})
}

func TestRequestReachesLocalService(t *testing.T) {
	h := newHarness(t)
	a := h.start(t, "alice-token", upstream(t, "web", echoPath()))

	if got, want := a.tunnels[0].URL, "http://"+a.host(); got != want {
		t.Errorf("tunnel URL = %q, want %q", got, want)
	}
	if !strings.HasSuffix(a.host(), "."+tunnelDomain) {
		t.Errorf("host %q is not under %s", a.host(), tunnelDomain)
	}
	status, body := h.get(t, a.host(), "/hello?x=1")
	if status != 200 || body != "/hello?x=1" {
		t.Errorf("got %d %q", status, body)
	}
}

func TestRequestedSubdomainAndSeveralTunnels(t *testing.T) {
	h := newHarness(t)
	one := upstream(t, "one", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "one") }))
	two := upstream(t, "two", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "two") }))
	one.Subdomain, two.Subdomain = "First", "second"
	h.start(t, "alice-token", one, two)

	for host, want := range map[string]string{"first." + tunnelDomain: "one", "SECOND." + tunnelDomain: "two"} {
		if status, body := h.get(t, host, "/"); status != 200 || body != want {
			t.Errorf("%s: got %d %q, want %q", host, status, body, want)
		}
	}
}

func TestForwardedHeadersCannotBeSpoofed(t *testing.T) {
	h := newHarness(t)
	seen := make(chan http.Header, 1)
	hosts := make(chan string, 1)
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		seen <- r.Header
		hosts <- r.Host
	})))

	req, _ := http.NewRequest("GET", "http://"+a.host()+"/", nil)
	req.Header.Set("X-Forwarded-For", "203.0.113.9")
	req.Header.Set("X-Forwarded-Proto", "https")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()

	hdr := <-seen
	if got := hdr.Get("X-Forwarded-For"); got != "127.0.0.1" {
		t.Errorf("X-Forwarded-For = %q, want the real client address only", got)
	}
	if got := hdr.Get("X-Forwarded-Proto"); got != "http" {
		t.Errorf("X-Forwarded-Proto = %q, want http", got)
	}
	if got := hdr.Get("X-Forwarded-Host"); got != a.host() {
		t.Errorf("X-Forwarded-Host = %q, want %q", got, a.host())
	}
	if got := <-hosts; got != a.host() {
		t.Errorf("Host = %q; a loopback upstream should see the public host %q", got, a.host())
	}
}

func TestTrustedProxyHeadersAreKept(t *testing.T) {
	h := newHarness(t, func(cfg *server.Config) {
		cfg.TrustedProxies = []netip.Prefix{netip.MustParsePrefix("127.0.0.0/8")}
		cfg.PublicScheme, cfg.PublicPort = "https", "443"
	})
	seen := make(chan http.Header, 1)
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		seen <- r.Header
	})))
	if got, want := a.tunnels[0].URL, "https://"+a.host(); got != want {
		t.Errorf("tunnel URL = %q, want %q", got, want)
	}

	// The test client plays the reverse proxy in front of the server.
	req, _ := http.NewRequest("GET", "http://"+a.host()+"/", nil)
	req.Header.Set("X-Forwarded-For", "203.0.113.9")
	req.Header.Set("X-Forwarded-Proto", "https")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()

	hdr := <-seen
	if got := hdr.Get("X-Forwarded-For"); got != "203.0.113.9" {
		t.Errorf("X-Forwarded-For = %q, want the visitor address the proxy reported", got)
	}
	if got := hdr.Get("X-Forwarded-Proto"); got != "https" {
		t.Errorf("X-Forwarded-Proto = %q, want https", got)
	}
}

func TestHostHeaderRewrite(t *testing.T) {
	h := newHarness(t)
	hosts := make(chan string, 1)
	tun := upstream(t, "web", http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) { hosts <- r.Host }))
	tun.HostHeader = agent.HostRewrite
	a := h.start(t, "alice-token", tun)

	h.get(t, a.host(), "/")
	if got := <-hosts; got != tun.Upstream.Host {
		t.Errorf("Host = %q, want the upstream's own %q", got, tun.Upstream.Host)
	}
}

func TestLargeUploadDoesNotStarveOtherRequests(t *testing.T) {
	h := newHarness(t)
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		sum := sha256.New()
		n, _ := io.Copy(sum, r.Body)
		fmt.Fprintf(w, "%d:%s", n, hex.EncodeToString(sum.Sum(nil)))
	})))

	const size = 64 << 20
	sent := sha256.New()
	// slowReader stretches the upload so the small requests overlap with it.
	body := io.TeeReader(&slowReader{remaining: size}, sent)

	uploaded := make(chan string, 1)
	go func() {
		resp, err := h.visitor.Post("http://"+a.host()+"/upload", "application/octet-stream", body)
		if err != nil {
			uploaded <- "error: " + err.Error()
			return
		}
		defer resp.Body.Close()
		b, _ := io.ReadAll(resp.Body)
		uploaded <- string(b)
	}()

	var slowest time.Duration
	small := 0
	for done := false; !done; {
		select {
		case got := <-uploaded:
			want := fmt.Sprintf("%d:%s", size, hex.EncodeToString(sent.Sum(nil)))
			if got != want {
				t.Errorf("upload came back as %q, want %q", got, want)
			}
			done = true
		default:
			started := time.Now()
			if status, _ := h.get(t, a.host(), "/small"); status != 200 {
				t.Fatalf("small request during upload: status %d", status)
			}
			slowest = max(slowest, time.Since(started))
			small++
		}
	}
	if small < 5 {
		t.Errorf("only %d small requests overlapped with the upload", small)
	}
	if slowest > time.Second {
		t.Errorf("a small request took %v while the upload was running", slowest)
	}
	t.Logf("%d small requests during a %d MiB upload, slowest %v", small, size>>20, slowest)
}

type slowReader struct{ remaining int }

func (r *slowReader) Read(p []byte) (int, error) {
	if r.remaining == 0 {
		return 0, io.EOF
	}
	n := min(len(p), r.remaining, 256<<10)
	for i := range p[:n] {
		p[i] = byte(r.remaining + i)
	}
	r.remaining -= n
	time.Sleep(time.Millisecond)
	return n, nil
}

func TestResponseStreamsWithoutBuffering(t *testing.T) {
	h := newHarness(t)
	release := make(chan struct{})
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprint(w, "data: one\n")
		w.(http.Flusher).Flush()
		<-release
		fmt.Fprint(w, "data: two\n")
	})))

	resp, err := h.visitor.Get("http://" + a.host() + "/events")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	lines := bufio.NewReader(resp.Body)

	first := make(chan string, 1)
	go func() {
		line, _ := lines.ReadString('\n')
		first <- line
	}()
	select {
	case line := <-first:
		if line != "data: one\n" {
			t.Fatalf("first event = %q", line)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("the first event was held back until the response finished")
	}
	close(release)
	if line, _ := lines.ReadString('\n'); line != "data: two\n" {
		t.Errorf("second event = %q", line)
	}
}

func TestWebSocketPassesThrough(t *testing.T) {
	h := newHarness(t)
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := websocket.Accept(w, r, nil)
		if err != nil {
			return
		}
		defer c.CloseNow()
		c.SetReadLimit(1 << 20)
		for {
			typ, data, err := c.Read(r.Context())
			if err != nil {
				return
			}
			if err := c.Write(r.Context(), typ, data); err != nil {
				return
			}
		}
	})))

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, _, err := websocket.Dial(ctx, "ws://"+a.host()+"/ws", &websocket.DialOptions{HTTPClient: h.visitor})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseNow()
	c.SetReadLimit(1 << 20)

	for _, msg := range []string{"hello", strings.Repeat("x", 200<<10), "bye"} {
		if err := c.Write(ctx, websocket.MessageText, []byte(msg)); err != nil {
			t.Fatal(err)
		}
		_, got, err := c.Read(ctx)
		if err != nil {
			t.Fatal(err)
		}
		if string(got) != msg {
			t.Fatalf("echo of a %d byte message came back as %d bytes", len(msg), len(got))
		}
	}
	c.Close(websocket.StatusNormalClosure, "")
}

func TestManyConcurrentRequests(t *testing.T) {
	h := newHarness(t)
	a := h.start(t, "alice-token", upstream(t, "web", echoPath()))

	var wg sync.WaitGroup
	errs := make(chan error, 300)
	for i := range 300 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			path := fmt.Sprintf("/item/%d", i)
			resp, err := h.visitor.Get("http://" + a.host() + path)
			if err != nil {
				errs <- err
				return
			}
			defer resp.Body.Close()
			body, _ := io.ReadAll(resp.Body)
			if resp.StatusCode != 200 || string(body) != path {
				errs <- fmt.Errorf("%s: got %d %q", path, resp.StatusCode, body)
			}
		}()
	}
	wg.Wait()
	close(errs)
	for err := range errs {
		t.Error(err)
	}
}

func TestAgentReconnectsAndKeepsItsAddress(t *testing.T) {
	h := newHarness(t)
	a := h.start(t, "alice-token", upstream(t, "web", echoPath()))
	host := a.host() // a random subdomain, which must survive the reconnect

	a.conns.drop()

	deadline := time.Now().Add(10 * time.Second)
	for {
		status, _ := h.get(t, host, "/again")
		if status == 200 {
			return
		}
		if status != http.StatusBadGateway {
			t.Fatalf("status %d while the agent was away, want 502", status)
		}
		if time.Now().After(deadline) {
			t.Fatal("the agent did not come back")
		}
		time.Sleep(20 * time.Millisecond)
	}
}

func TestLocalServiceDown(t *testing.T) {
	h := newHarness(t)
	tun := upstream(t, "web", echoPath())
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	tun.Upstream.Host = ln.Addr().String()
	ln.Close() // nothing listens here any more
	a := h.start(t, "alice-token", tun)

	status, body := h.get(t, a.host(), "/")
	if status != http.StatusBadGateway || !strings.Contains(body, "local service") {
		t.Errorf("got %d %q, want a 502 that names the local service", status, body)
	}
}

func TestUnknownHost(t *testing.T) {
	h := newHarness(t)
	if status, _ := h.get(t, "nobody."+tunnelDomain, "/"); status != http.StatusNotFound {
		t.Errorf("status %d, want 404", status)
	}
}

func TestBadTokenIsFatal(t *testing.T) {
	h := newHarness(t)
	a, _ := h.launch(t, "wrong-token", upstream(t, "web", echoPath()))
	var fatal *agent.FatalError
	if err := a.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "token") {
		t.Errorf("agent returned %v, want a fatal token error", err)
	}
}

func TestSubdomainOfAnotherAccountIsRefused(t *testing.T) {
	h := newHarness(t)
	mine := upstream(t, "web", echoPath())
	mine.Subdomain = "shop"
	h.start(t, "alice-token", mine)

	theirs := upstream(t, "web", echoPath())
	theirs.Subdomain = "shop"
	b, _ := h.launch(t, "bob-token", theirs)
	var fatal *agent.FatalError
	if err := b.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "another account") {
		t.Errorf("agent returned %v, want a fatal error about another account", err)
	}
}

func TestAgentHostCannotBeClaimed(t *testing.T) {
	h := newHarness(t)
	tun := upstream(t, "web", echoPath())
	tun.Subdomain = "connect"
	a, _ := h.launch(t, "alice-token", tun)
	var fatal *agent.FatalError
	if err := a.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "reserved") {
		t.Errorf("agent returned %v, want a fatal error about a reserved name", err)
	}
}

func TestPasswordProtectedTunnel(t *testing.T) {
	h := newHarness(t)
	reached := make(chan http.Header, 8)
	tun := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reached <- r.Header
		fmt.Fprint(w, "inside")
	}))
	tun.Auth = []string{"ada:correct horse", "grace:another one"}
	a := h.start(t, "alice-token", tun)

	request := func(user, password string) *http.Response {
		req, _ := http.NewRequest("GET", "http://"+a.host()+"/.env", nil)
		if user != "" {
			req.SetBasicAuth(user, password)
		}
		resp, err := h.visitor.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { resp.Body.Close() })
		return resp
	}

	for name, creds := range map[string][2]string{
		"no credentials":        {"", ""},
		"wrong password":        {"ada", "wrong"},
		"password of the other": {"ada", "another one"},
	} {
		resp := request(creds[0], creds[1])
		if resp.StatusCode != http.StatusUnauthorized {
			t.Errorf("%s: status %d, want 401", name, resp.StatusCode)
		}
		if !strings.HasPrefix(resp.Header.Get("WWW-Authenticate"), "Basic ") {
			t.Errorf("%s: no Basic challenge, so a browser would not ask for a password", name)
		}
	}
	select {
	case <-reached:
		t.Fatal("a request without valid credentials reached the local service")
	default:
	}

	for _, creds := range [][2]string{{"ada", "correct horse"}, {"grace", "another one"}} {
		resp := request(creds[0], creds[1])
		if resp.StatusCode != 200 {
			t.Fatalf("%s: status %d, want 200", creds[0], resp.StatusCode)
		}
		if got := (<-reached).Get("Authorization"); got != "" {
			t.Errorf("the local service received the tunnel's credentials: %q", got)
		}
	}
}

func TestUnprotectedTunnelPassesAuthorizationThrough(t *testing.T) {
	h := newHarness(t)
	seen := make(chan string, 1)
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		seen <- r.Header.Get("Authorization")
	})))

	req, _ := http.NewRequest("GET", "http://"+a.host()+"/", nil)
	req.Header.Set("Authorization", "Bearer app-token")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if got := <-seen; got != "Bearer app-token" {
		t.Errorf("Authorization = %q, want the application's own header untouched", got)
	}
}

func TestTeammateCannotTakeAnAddressInUse(t *testing.T) {
	h := newHarness(t)
	mine := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "first") }))
	mine.Subdomain = "staging"
	first := h.start(t, "alice-token", mine)

	// Same account, different token: a teammate's machine.
	theirs := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "second") }))
	theirs.Subdomain = "staging"
	b, _ := h.launch(t, "alice-second-token", theirs)
	var fatal *agent.FatalError
	if err := b.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "your team") {
		t.Fatalf("teammate's agent returned %v, want a refusal naming the team", err)
	}
	if _, body := h.get(t, "staging."+tunnelDomain, "/"); body != "first" {
		t.Fatalf("got %q, want the first agent to keep the address", body)
	}

	// Once the first agent is gone, the teammate may pick the name up. The
	// server frees the name when it notices the connection closing, which is
	// a moment after the agent has returned.
	first.stop()
	h.startWhenFree(t, "alice-second-token", theirs)
	if _, body := h.get(t, "staging."+tunnelDomain, "/"); body != "second" {
		t.Errorf("got %q, want the teammate's agent to answer now", body)
	}
}

func TestSecondAgentOfSameAccountTakesOver(t *testing.T) {
	h := newHarness(t)
	first := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "first") }))
	first.Subdomain = "demo"
	a := h.start(t, "alice-token", first)

	second := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "second") }))
	second.Subdomain = "demo"
	h.start(t, "alice-token", second)

	// The first agent must stop for good; if it reconnected, the two would
	// keep stealing the address from each other.
	var fatal *agent.FatalError
	if err := a.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "another agent") {
		t.Errorf("first agent returned %v, want a fatal takeover notice", err)
	}
	if status, body := h.get(t, "demo."+tunnelDomain, "/"); status != 200 || body != "second" {
		t.Errorf("got %d %q, want the second agent to answer", status, body)
	}
}

func TestAgentOpenedStreamEndsSession(t *testing.T) {
	h := newHarness(t)
	mux, ctrl := rawAgent(t, h, "alice-token")
	welcome := handshake(t, ctrl, proto.Hello{AgentVersion: "test", Tunnels: []proto.TunnelRequest{{Name: "web", Proto: proto.ProtoHTTP}}})
	if welcome.Error != "" || len(welcome.Tunnels) != 1 {
		t.Fatalf("welcome = %+v", welcome)
	}

	// Only the server opens streams after the control stream. One the agent
	// opens would sit in the server's memory, with its data, for as long as
	// the session lasts.
	extra, err := mux.OpenStream()
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		_ = extra.SetWriteDeadline(time.Now().Add(5 * time.Second))
		_, _ = extra.Write(make([]byte, 256<<10))
	}()
	select {
	case <-mux.CloseChan():
	case <-time.After(5 * time.Second):
		t.Fatal("the server kept the session of an agent that opened a stream of its own")
	}
	if _, err := extra.Write([]byte("more")); err == nil {
		t.Error("writing to the extra stream still worked after the session ended")
	}
}

func TestOneTokenCannotHoldUnlimitedSessions(t *testing.T) {
	h := newHarness(t)
	held := 0
	for {
		ws, status := dialAgent(t, h, "alice-token")
		if ws == nil {
			if status != http.StatusTooManyRequests {
				t.Fatalf("session %d: status %d, want 429", held+1, status)
			}
			break
		}
		t.Cleanup(func() { ws.CloseNow() })
		if held++; held > 100 {
			t.Fatal("the server accepted more than 100 sessions for one token")
		}
	}
	// Another token is not affected.
	if ws, status := dialAgent(t, h, "bob-token"); ws == nil {
		t.Errorf("another token's session: status %d", status)
	} else {
		ws.CloseNow()
	}
}

func TestATunnelCannotSetCookiesForOtherTunnels(t *testing.T) {
	h := newHarness(t)
	evil := upstream(t, "evil", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Add("Set-Cookie", "sid=planted; Domain=."+strings.ToUpper(tunnelDomain)+"; Path=/")
		w.Header().Add("Set-Cookie", "parent=planted; Path=/; domain=test")
		w.Header().Add("Set-Cookie", "own=kept; Domain=bobsite."+tunnelDomain+"; Path=/")
		fmt.Fprint(w, "bob's page")
	}))
	evil.Subdomain = "bobsite"
	h.start(t, "bob-token", evil)

	seen := make(chan string, 1)
	victim := upstream(t, "shop", http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		seen <- r.Header.Get("Cookie")
	}))
	victim.Subdomain = "alicesite"
	h.start(t, "alice-token", victim)

	jar, _ := cookiejar.New(nil)
	browser := &http.Client{Transport: pinned(h.addr, nil), Jar: jar}
	resp, err := browser.Get("http://bobsite." + tunnelDomain + "/")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	want := []string{"sid=planted; Path=/", "parent=planted; Path=/", "own=kept; Domain=bobsite." + tunnelDomain + "; Path=/"}
	if got := resp.Header.Values("Set-Cookie"); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Errorf("Set-Cookie = %q, want %q", got, want)
	}

	resp, err = browser.Get("http://alicesite." + tunnelDomain + "/")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if got := <-seen; got != "" {
		t.Errorf("another team's tunnel received the cookies %q", got)
	}
}

func BenchmarkDownload(b *testing.B) {
	h := newHarness(b)
	const size = 64 << 20
	chunk := make([]byte, 64<<10)
	a := h.start(b, "alice-token", upstream(b, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		for sent := 0; sent < size; sent += len(chunk) {
			if _, err := w.Write(chunk); err != nil {
				return
			}
		}
	})))

	b.SetBytes(size)
	for b.Loop() {
		resp, err := h.visitor.Get("http://" + a.host() + "/file")
		if err != nil {
			b.Fatal(err)
		}
		n, err := io.Copy(io.Discard, resp.Body)
		resp.Body.Close()
		if err != nil || n != size {
			b.Fatalf("read %d bytes, err %v", n, err)
		}
	}
}

func BenchmarkSmallRequest(b *testing.B) {
	h := newHarness(b)
	a := h.start(b, "alice-token", upstream(b, "web", echoPath()))
	for b.Loop() {
		resp, err := h.visitor.Get("http://" + a.host() + "/")
		if err != nil {
			b.Fatal(err)
		}
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()
	}
}
