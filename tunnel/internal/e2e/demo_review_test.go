package e2e

// Regression tests for what the security review of the demo found before
// its first release. Each of these passed the other way round then.

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// A new agent with the same token used to bring a fresh allowance: through
// 20 takeovers a tunnel limited to 3 requests a minute answered 60.
func TestADemoTunnelKeepsItsRequestAllowanceWhenAnotherAgentTakesItOver(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) { c.DemoRequestsPerMinute = 3 })
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	host := a.host()
	label := strings.TrimSuffix(host, "."+tunnelDomain)

	served := 0
	for round := 0; round < 5; round++ {
		for {
			status, _ := h.get(t, host, "/x")
			if status != http.StatusOK {
				break
			}
			served++
		}
		again := upstream(t, "web", echoPath())
		again.Subdomain = label
		if b := h.start(t, "eh_demo1", again); b.host() != host {
			t.Fatalf("the second agent got %s, want %s back", b.host(), host)
		}
	}
	// Three, and what fills up again while the test runs.
	if served > 4 {
		t.Fatalf("%d requests were answered through 5 takeovers, with a limit of 3 a minute", served)
	}
}

// The same for the agent's own reconnect after its connections drop.
func TestADemoTunnelKeepsItsRequestAllowanceWhenItsAgentReconnects(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) { c.DemoRequestsPerMinute = 3 })
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	host := a.host()

	for i := 0; i < 3; i++ {
		if status, _ := h.get(t, host, "/x"); status != http.StatusOK {
			t.Fatalf("request %d: %d", i+1, status)
		}
	}
	a.conns.drop()
	deadline := time.Now().Add(10 * time.Second)
	for {
		status, _ := h.get(t, host, "/x")
		if status == http.StatusTooManyRequests {
			return // the agent is back, and the allowance is as it was left
		}
		if status == http.StatusOK {
			t.Fatal("the reconnected agent answered a fourth request within the minute")
		}
		if time.Now().After(deadline) {
			t.Fatalf("the agent did not come back; last status %d", status)
		}
		time.Sleep(20 * time.Millisecond)
	}
}

type zeros struct{}

func (zeros) Read(p []byte) (int, error) { clear(p); return len(p), nil }

// One admitted request used to carry any amount of data: a WebSocket relayed
// 125 MiB, a response 64 MiB and an upload 64 MiB under a limit of three
// requests a minute.
func TestADemoTunnelCarriesItsByteAllowanceAndNoMore(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) { c.DemoMegabytesPerMinute = 1 })
	// What the service behind each tunnel has taken in as uploads.
	var received atomic.Int64
	big := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/ws":
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
		case "/stream":
			chunk := bytes.Repeat([]byte("x"), 64<<10)
			for i := 0; i < 256; i++ { // 16 MiB
				if _, err := w.Write(chunk); err != nil {
					return
				}
			}
		case "/upload":
			buf := make([]byte, 32<<10)
			for {
				n, err := r.Body.Read(buf)
				received.Add(int64(n))
				if err != nil {
					return
				}
			}
		}
	})
	demo := h.start(t, "eh_demo1", upstream(t, "web", big))
	team := h.start(t, "eh_team", upstream(t, "web", big))

	// What arrives within a fixed time, by each of the three ways.
	const window = 1500 * time.Millisecond
	download := func(host string) int64 {
		ctx, cancel := context.WithTimeout(context.Background(), window)
		defer cancel()
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "http://"+host+"/stream", nil)
		resp, err := h.visitor.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		n, _ := io.Copy(io.Discard, resp.Body)
		return n
	}
	relay := func(host string) int64 {
		ctx, cancel := context.WithTimeout(context.Background(), window)
		defer cancel()
		ws, _, err := websocket.Dial(ctx, "ws://"+host+"/ws", &websocket.DialOptions{HTTPClient: h.visitor})
		if err != nil {
			t.Fatal(err)
		}
		defer ws.CloseNow()
		ws.SetReadLimit(1 << 20)
		message := bytes.Repeat([]byte("y"), 64<<10)
		var relayed int64
		for i := 0; i < 128; i++ { // 8 MiB each way
			if err := ws.Write(ctx, websocket.MessageBinary, message); err != nil {
				break
			}
			_, data, err := ws.Read(ctx)
			if err != nil {
				break
			}
			relayed += int64(len(data))
		}
		return relayed
	}
	// An upload is measured where it arrives: what a client has handed to
	// its own socket says little about what the server passed on.
	upload := func(host string) int64 {
		ctx, cancel := context.WithTimeout(context.Background(), window)
		defer cancel()
		before := received.Load()
		req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "http://"+host+"/upload", io.LimitReader(zeros{}, 16<<20))
		if resp, err := h.visitor.Do(req); err == nil {
			resp.Body.Close()
		}
		return received.Load() - before
	}

	// The first megabyte passes at once; after it about 17 KiB a second do.
	// Buffers between the two ends add a little to what one side counts.
	const allowed = 3 << 20
	if n := download(demo.host()); n > allowed {
		t.Errorf("a demo tunnel sent a visitor %d KiB in %v, with 1 MiB a minute", n>>10, window)
	}
	if n := relay(demo.host()); n > allowed {
		t.Errorf("a demo tunnel relayed %d KiB over a WebSocket in %v, after its allowance was spent", n>>10, window)
	}
	if n := upload(demo.host()); n > allowed {
		t.Errorf("a demo tunnel took an upload of %d KiB in %v, after its allowance was spent", n>>10, window)
	}

	// A team's tunnel on the same server is not held back.
	if n := download(team.host()); n < 16<<20 {
		t.Errorf("a team's tunnel sent only %d KiB of 16 MiB in %v", n>>10, window)
	}
	if n := relay(team.host()); n < 8<<20 {
		t.Errorf("a team's tunnel relayed only %d KiB of 8 MiB in %v", n>>10, window)
	}
	if n := upload(team.host()); n < 8<<20 {
		t.Errorf("a team's tunnel took only %d KiB of a 16 MiB upload in %v", n>>10, window)
	}
}

// A browser that sends no Fetch Metadata, as all do over plain HTTP, and is
// made to post a form to a demo tunnel used to get the tunnelled page, or
// its password prompt, without the notice.
func TestAFormPostedToADemoTunnelByABrowserWithoutFetchMetadataGetsTheNotice(t *testing.T) {
	_, h := demoServer(t)
	a := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/prompt" {
			w.Header().Set("WWW-Authenticate", `Basic realm="Your bank: sign in again"`)
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		fmt.Fprint(w, "<h1>Bank login</h1>")
	})))

	for _, path := range []string{"/", "/prompt"} {
		resp, body := browse(t, h, "POST", "http://"+a.host()+path, "Sec-Fetch-Mode", "", "Sec-Fetch-Dest", "", "Content-Type", "application/x-www-form-urlencoded")
		// 403, so that a program sending a form does not take the notice for a delivery.
		if resp.StatusCode != http.StatusForbidden || strings.Contains(body, "Bank login") || resp.Header.Get("WWW-Authenticate") != "" {
			t.Fatalf("POST %s: %d, WWW-Authenticate %q, body %.60q; want the notice", path, resp.StatusCode, resp.Header.Get("WWW-Authenticate"), body)
		}
		noticeLink(t, body)
	}

	// A webhook sender posts a form too, but does not ask for a page.
	req, _ := http.NewRequest(http.MethodPost, "http://"+a.host()+"/", strings.NewReader("event=paid"))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "*/*")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if !strings.Contains(string(body), "Bank login") {
		t.Fatalf("a webhook got %d %.60q, want the tunnelled service", resp.StatusCode, body)
	}
}

// The address a visitor first asked for travels in the button's "to", where
// the log's masking of secret-looking parameters did not look.
func TestTheLogOfADemoTunnelDoesNotKeepTheAddressInsideTheNoticeButton(t *testing.T) {
	cp, h := demoServer(t)
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	_, body := browse(t, h, "GET", "http://"+a.host()+"/reset?token=SECRET123")
	browse(t, h, "GET", "http://"+a.host()+noticeLink(t, body))

	logs := waitForLogs(t, cp, 2)
	for _, ev := range logs {
		if strings.Contains(ev.Path, "SECRET123") {
			t.Errorf("the log kept %q", ev.Path)
		}
	}
	if want := "/.exposehub/demo-continue?to=[redacted]&sig=[redacted]"; logs[1].Path != want {
		t.Errorf("the button's request is logged as %q, want %q", logs[1].Path, want)
	}
}

// A page fetched in the background gets no notice. A browser must not show
// that copy from its cache when the visitor later opens the address.
func TestAnswersOfADemoTunnelSayTheyDependOnHowTheyWereAskedFor(t *testing.T) {
	_, h := demoServer(t)
	handler := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Vary", "Accept-Encoding")
		w.Header().Set("Cache-Control", "max-age=3600")
		fmt.Fprint(w, "page")
	})
	demo := h.start(t, "eh_demo1", upstream(t, "web", handler))
	team := h.start(t, "eh_team", upstream(t, "web", handler))

	vary := func(host string) string {
		resp, err := h.visitor.Get("http://" + host + "/")
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
		return strings.Join(resp.Header.Values("Vary"), ", ")
	}
	if got := vary(demo.host()); got != "Accept-Encoding, Sec-Fetch-Mode" {
		t.Errorf("a demo tunnel answers Vary: %s", got)
	}
	if got := vary(team.host()); got != "Accept-Encoding" {
		t.Errorf("a team's tunnel answers Vary: %s", got)
	}
}

// The first fix for the form above took every request that names HTML for a
// page. Java's HTTP client names it by default, so its webhooks got the
// notice, with 200, and never arrived.
func TestAWebhookFromAClientThatNamesHTMLByDefaultReachesADemoTunnel(t *testing.T) {
	_, h := demoServer(t)
	hits := make(chan string, 1)
	a := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits <- r.URL.Path
		w.WriteHeader(http.StatusNoContent)
	})))
	req, _ := http.NewRequest(http.MethodPost, "http://"+a.host()+"/hook", strings.NewReader(`{"id":1}`))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "text/html, image/gif, image/jpeg, *; q=.2, */*; q=.2")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	select {
	case path := <-hits:
		if path != "/hook" || resp.StatusCode != http.StatusNoContent {
			t.Fatalf("the service got %s and the sender %d", path, resp.StatusCode)
		}
	case <-time.After(2 * time.Second):
		t.Fatalf("the webhook did not arrive; its sender got %d", resp.StatusCode)
	}
}

// A request that waits for the byte allowance used to keep waiting, with its
// stream to the agent open, after the visitor had left.
func TestADemoTunnelLetsGoOfRequestsWhoseVisitorsLeft(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) { c.DemoMegabytesPerMinute = 1 })
	var streaming atomic.Int64
	chunk := make([]byte, 64<<10)
	a := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		streaming.Add(1)
		defer streaming.Add(-1)
		for {
			if _, err := w.Write(chunk); err != nil {
				return
			}
		}
	})))

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	const visitors = 20
	for i := 0; i < visitors; i++ {
		go func() {
			defer func() { done <- struct{}{} }()
			req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "http://"+a.host()+"/stream", nil)
			if resp, err := h.visitor.Do(req); err == nil {
				_, _ = io.Copy(io.Discard, resp.Body)
				resp.Body.Close()
			}
		}()
	}
	// Long enough for the allowance to be spent and every stream to be waiting.
	time.Sleep(time.Second)
	if n := streaming.Load(); n != visitors {
		t.Fatalf("%d of %d streams reached the service", n, visitors)
	}
	cancel()
	for i := 0; i < visitors; i++ {
		<-done
	}

	// At 1 MiB a minute the debt of 20 streams takes over a minute to repay.
	deadline := time.Now().Add(5 * time.Second)
	for streaming.Load() > 0 {
		if time.Now().After(deadline) {
			t.Fatalf("%d streams to the service are still open 5s after their visitors left", streaming.Load())
		}
		time.Sleep(20 * time.Millisecond)
	}
}

// The meter sits between the proxy and the visitor. Events of a stream must
// still arrive one by one, and a WebSocket must still open.
func TestADemoTunnelStillStreamsEventsAndOpensWebSockets(t *testing.T) {
	_, h := demoServer(t)
	a := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/ws" {
			c, err := websocket.Accept(w, r, nil)
			if err != nil {
				return
			}
			defer c.CloseNow()
			if typ, data, err := c.Read(r.Context()); err == nil {
				_ = c.Write(r.Context(), typ, append([]byte("echo:"), data...))
			}
			return
		}
		w.Header().Set("Content-Type", "text/event-stream")
		for i := 0; i < 3; i++ {
			fmt.Fprintf(w, "data: %d\n\n", i)
			_ = http.NewResponseController(w).Flush()
			time.Sleep(400 * time.Millisecond)
		}
	})))

	start := time.Now()
	resp, err := h.visitor.Get("http://" + a.host() + "/events")
	if err != nil {
		t.Fatal(err)
	}
	first := make([]byte, 16)
	n, err := io.ReadAtLeast(resp.Body, first, len("data: 0\n\n"))
	resp.Body.Close()
	if err != nil || !strings.HasPrefix(string(first[:n]), "data: 0") {
		t.Fatalf("the first event came as %q (%v)", first[:n], err)
	}
	// The service takes 1.2s to send all three; held back, the first would come with the last.
	if took := time.Since(start); took > 350*time.Millisecond {
		t.Errorf("the first event took %v", took.Round(time.Millisecond))
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	ws, _, err := websocket.Dial(ctx, "ws://"+a.host()+"/ws", &websocket.DialOptions{HTTPClient: h.visitor})
	if err != nil {
		t.Fatal(err)
	}
	defer ws.CloseNow()
	if err := ws.Write(ctx, websocket.MessageText, []byte("hi")); err != nil {
		t.Fatal(err)
	}
	if _, data, err := ws.Read(ctx); err != nil || string(data) != "echo:hi" {
		t.Fatalf("the WebSocket answered %q (%v)", data, err)
	}
}
