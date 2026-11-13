// Package agent implements the client side of a tunnel: it keeps a session to
// the tunnel server open and forwards the requests it receives to local
// services.
package agent

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log"
	"log/slog"
	"math/rand/v2"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"
	"github.com/hashicorp/yamux"

	"github.com/jkbennemann/exposehub/tunnel/internal/errorpage"
	"github.com/jkbennemann/exposehub/tunnel/internal/inspector"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/transport"
)

const (
	handshakeTimeout = 15 * time.Second
	backoffMin       = 500 * time.Millisecond
	backoffMax       = 30 * time.Second
	// A session that lasted this long counts as healthy and resets the backoff.
	stableAfter = 30 * time.Second
)

// Host header modes for Tunnel.HostHeader.
const (
	HostAuto     = ""
	HostPreserve = "preserve"
	HostRewrite  = "rewrite"
)

// Tunnel exposes one local service.
type Tunnel struct {
	Name     string
	Upstream *url.URL
	// Domain and Subdomain are optional requests; the server fills in the rest.
	Domain    string
	Subdomain string
	// Hostname asks for a domain of the team's own, connected in the
	// dashboard, instead of a subdomain of the server's tunnel domain.
	Hostname string
	// LogBodies asks the server to log headers and bodies too; NoLog asks it
	// to log nothing. Without either, servers that log record a summary of
	// each request.
	LogBodies bool
	NoLog     bool
	// Auth lists "user:password" pairs. With at least one, the server lets
	// visitors in only with HTTP Basic credentials matching a pair.
	Auth []string
	// Allow limits who may reach the tunnel: addresses, networks in CIDR
	// notation, or names of the team's networks in the dashboard. The server
	// checks it on top of the dashboard's own rules.
	Allow []string
	// HostHeader chooses what the local service sees as Host. HostPreserve
	// keeps the public hostname, HostRewrite sends the upstream's own. HostAuto
	// preserves for loopback upstreams and rewrites for named ones such as
	// mysite.test, which usually select a virtual host by name. In HostAuto a
	// loopback upstream that refuses the public name, as Vite and webpack dev
	// servers do, is switched to its own name on the first such answer.
	// Unless it is HostPreserve, a Next.js dev server on a loopback upstream
	// that refuses the tunnel's origin gets its own on its dev endpoints.
	HostHeader string
	// Folder is the local folder the command serves at Upstream, for a
	// target such as ./dist. Run does not read it; it is there so that what
	// the person sees can name the folder rather than a local port.
	Folder string
}

// Request describes one proxied request, for display.
type Request struct {
	Tunnel   string
	Method   string
	Path     string
	Status   int
	Duration time.Duration
	// Replay is true for a request sent again from the inspector.
	Replay bool
}

// Config configures Run.
type Config struct {
	// Server is the tunnel server, for example https://connect.example.eu.
	Server  string
	Token   string
	Tunnels []Tunnel
	Version string
	Logger  *slog.Logger
	// HTTPClient dials the server. Nil uses the default, which honours HTTPS_PROXY.
	HTTPClient *http.Client
	// OnConnect is called after every successful (re)connect.
	OnConnect func([]proto.TunnelInfo)
	// OnRequest is called after every proxied request.
	OnRequest func(Request)
	// Inspector, if set, keeps every request for the local inspector page and
	// can replay them.
	Inspector *inspector.Store
	// OnNewerAgent is called once if the server hands out a different agent
	// version than Version. Development builds ("dev") are never told.
	OnNewerAgent func(offered string)
	// OnNotice is called with something the developer should know about a
	// tunnel, each thing once: that a dev server refused the public hostname
	// and what the agent did or what they can do. Nil logs it as a warning.
	OnNotice func(tunnel, text string)
}

// FatalError is returned by Run when reconnecting cannot help.
type FatalError struct{ Msg string }

func (e *FatalError) Error() string { return e.Msg }

// Run keeps the tunnels open until ctx is cancelled, reconnecting with
// backoff. It returns a *FatalError when the server refuses the agent.
func Run(ctx context.Context, cfg Config) error {
	if cfg.Logger == nil {
		cfg.Logger = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	endpoint, err := agentEndpoint(cfg.Server)
	if err != nil {
		return &FatalError{Msg: err.Error()}
	}
	a := &agent{cfg: cfg, endpoint: endpoint, tunnels: map[string]*tunnel{}}
	for _, t := range cfg.Tunnels {
		if t.Name == "" || a.tunnels[t.Name] != nil {
			return &FatalError{Msg: fmt.Sprintf("tunnel name %q is empty or used twice", t.Name)}
		}
		a.tunnels[t.Name] = a.newTunnel(t)
	}
	if cfg.Inspector != nil {
		cfg.Inspector.SetReplayer(a.replay)
	}
	if len(a.tunnels) == 0 {
		return &FatalError{Msg: "no tunnels configured"}
	}

	delay := backoffMin
	for {
		started := time.Now()
		err := a.session(ctx)
		if ctx.Err() != nil {
			return nil
		}
		var fatal *FatalError
		if errors.As(err, &fatal) {
			return err
		}
		if time.Since(started) > stableAfter {
			delay = backoffMin
		}
		// Jitter spreads out the reconnects of many agents after a server restart.
		wait := delay/2 + rand.N(delay/2)
		cfg.Logger.Warn("disconnected from the tunnel server, reconnecting", "in", wait.Round(time.Millisecond), "err", err)
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(wait):
		}
		delay = min(delay*2, backoffMax)
	}
}

type tunnel struct {
	Tunnel
	proxy *httputil.ReverseProxy
	// assigned is the subdomain the server gave out, requested again on
	// reconnect so the public URL survives a dropped connection.
	assigned string
	// rewriteHost says whether the local service gets its own name as Host
	// instead of the public one. HostHeader decides it, except that HostAuto
	// turns it on once a dev server has refused the public name.
	rewriteHost atomic.Bool
	// localOrigin is set once a Next.js dev server has refused the tunnel's
	// own origin: from then on its dev endpoints get the service's own.
	localOrigin atomic.Bool
	// toldNext is set once the Next.js hot-reload notice has been given.
	toldNext atomic.Bool
}

func (a *agent) newTunnel(cfg Tunnel) *tunnel {
	t := &tunnel{Tunnel: cfg}
	loopback := isLoopback(cfg.Upstream.Hostname())
	t.rewriteHost.Store(cfg.HostHeader == HostRewrite || (cfg.HostHeader == HostAuto && !loopback))
	var switched, adopted func(publicHost string)
	if cfg.HostHeader == HostAuto && loopback {
		switched = func(publicHost string) { a.notice(t, hostSwitchNotice(cfg.Upstream, publicHost)) }
	}
	if cfg.HostHeader != HostPreserve && loopback {
		adopted = func(publicHost string) { a.notice(t, nextOriginNotice(cfg.Upstream, publicHost)) }
	}
	t.proxy = newUpstreamProxy(t, switched, adopted)
	return t
}

// notice passes something the developer should know to OnNotice.
func (a *agent) notice(t *tunnel, text string) {
	if a.cfg.OnNotice != nil {
		a.cfg.OnNotice(t.Name, text)
		return
	}
	a.cfg.Logger.Warn(text, "tunnel", t.Name)
}

type agent struct {
	cfg       Config
	endpoint  string
	tunnels   map[string]*tunnel
	toldNewer bool
}

// session runs one connection to the server and returns when it ends.
func (a *agent) session(ctx context.Context) error {
	dialCtx, cancelDial := context.WithTimeout(ctx, handshakeTimeout)
	defer cancelDial()
	ws, resp, err := websocket.Dial(dialCtx, a.endpoint, &websocket.DialOptions{
		HTTPClient: a.cfg.HTTPClient,
		HTTPHeader: http.Header{
			"Authorization": {"Bearer " + a.cfg.Token},
			"User-Agent":    {"exposehub-agent/" + a.cfg.Version},
		},
	})
	if err != nil {
		if resp != nil {
			switch resp.StatusCode {
			case http.StatusUnauthorized, http.StatusForbidden:
				return &FatalError{Msg: "the server rejected the token"}
			case http.StatusNotFound:
				return &FatalError{Msg: a.cfg.Server + " is not an exposehub agent endpoint"}
			}
		}
		return err
	}

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	mux, err := yamux.Client(transport.NetConn(ctx, ws), transport.MuxConfig(a.cfg.Logger))
	if err != nil {
		return err
	}
	defer mux.Close()

	ctrl, err := mux.OpenStream()
	if err != nil {
		return err
	}
	hello := proto.Hello{Version: proto.Version, AgentVersion: a.cfg.Version}
	for _, t := range a.cfg.Tunnels { // config order, so URLs print in a stable order
		sub := t.Subdomain
		if assigned := a.tunnels[t.Name].assigned; assigned != "" {
			sub = assigned
		}
		req := proto.TunnelRequest{
			Name: t.Name, Proto: proto.ProtoHTTP, Domain: t.Domain, Subdomain: sub, Hostname: t.Hostname,
			LogBodies: t.LogBodies, NoLog: t.NoLog, Allow: t.Allow,
		}
		for _, pair := range t.Auth {
			sum := sha256.Sum256([]byte(pair))
			req.Auth = append(req.Auth, proto.AuthPrefix+hex.EncodeToString(sum[:]))
		}
		hello.Tunnels = append(hello.Tunnels, req)
	}
	_ = ctrl.SetDeadline(time.Now().Add(handshakeTimeout))
	if err := proto.WriteFrame(ctrl, hello); err != nil {
		return err
	}
	var welcome proto.Welcome
	if err := proto.ReadFrame(ctrl, &welcome); err != nil {
		return err
	}
	_ = ctrl.SetDeadline(time.Time{})
	if welcome.Error != "" {
		return &FatalError{Msg: welcome.Error}
	}

	byID := map[string]*tunnel{}
	for _, info := range welcome.Tunnels {
		t := a.tunnels[info.Name]
		if t == nil {
			return fmt.Errorf("server opened unknown tunnel %q", info.Name)
		}
		if len(t.Auth) > 0 && !info.Protected {
			// An older server ignores the request, and the tunnel would be open.
			return &FatalError{Msg: "the server did not confirm password protection; it needs to be updated before --auth can be used"}
		}
		t.assigned = info.Subdomain
		byID[info.ID] = t
	}
	if a.cfg.OnConnect != nil {
		a.cfg.OnConnect(welcome.Tunnels)
	}
	if a.cfg.Inspector != nil {
		open := make([]inspector.Tunnel, 0, len(welcome.Tunnels))
		for _, info := range welcome.Tunnels {
			t := a.tunnels[info.Name]
			open = append(open, inspector.Tunnel{Name: info.Name, URL: info.URL, Upstream: t.Upstream.String(), Protected: info.Protected})
		}
		a.cfg.Inspector.SetTunnels(open)
	}
	if offered := welcome.AgentVersion; offered != "" && offered != a.cfg.Version && a.cfg.Version != "dev" &&
		a.cfg.OnNewerAgent != nil && !a.toldNewer {
		a.toldNewer = true
		a.cfg.OnNewerAgent(offered)
	}

	ln := newStreamListener(mux)
	// No timeouts: the only peer is the authenticated tunnel server, which
	// decides how long idle streams live and when requests are abandoned.
	srv := &http.Server{
		Handler:     a.handler(byID),
		ConnContext: connContext,
		ErrorLog:    slog.NewLogLogger(a.cfg.Logger.Handler(), slog.LevelDebug),
	}
	go func() { _ = srv.Serve(ln) }()
	defer srv.Close()

	// The control stream ends when the session does, so reading it is how the
	// agent waits. Anything the server says before closing arrives first.
	for {
		var msg proto.Message
		if err := proto.ReadFrame(ctrl, &msg); err != nil {
			return fmt.Errorf("connection lost: %w", err)
		}
		if msg.Type == proto.MsgGoodbye {
			if msg.Final {
				return &FatalError{Msg: "the server closed the tunnel: " + msg.Reason}
			}
			return fmt.Errorf("the server closed the session: %s", msg.Reason)
		}
	}
}

func (a *agent) handler(byID map[string]*tunnel) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		id, _ := r.Context().Value(tunnelKey{}).(string)
		t := byID[id]
		if t == nil {
			errorpage.Write(w, r, errorpage.UnknownTunnel())
			return
		}
		a.serve(t, w, r, "")
	})
}

// serve passes one request to the local service, recording it for the
// inspector. replayOf names the captured request it repeats, if any.
func (a *agent) serve(t *tunnel, w http.ResponseWriter, r *http.Request, replayOf string) {
	started := time.Now()
	var rec *inspector.Recorder
	if a.cfg.Inspector != nil {
		rec = a.cfg.Inspector.Record(t.Name, r, replayOf)
		r.Body = rec.Body(r.Body)
		w = rec.Writer(w)
	}
	sw := &statusWriter{ResponseWriter: w}
	t.proxy.ServeHTTP(sw, r)
	status := sw.status
	if status == 0 && r.Header.Get("Upgrade") != "" {
		// The proxy answers an upgrade on the hijacked connection, past the writer.
		status = http.StatusSwitchingProtocols
	}
	// A visitor who went away also ends here; that is not the service's fault.
	switch {
	case sw.err == nil || r.Context().Err() != nil:
	case nextRefusedSocket(sw.err) && nextInternal(r.URL.Path) && ownOrigin(r) == "":
		// A page of some other site tried the dev server's hot-reload socket.
		// Turning that down is what the check is for; nothing is wrong.
		a.cfg.Logger.Debug("Next.js refused a connection from another origin", "tunnel", t.Name, "origin", r.Header.Get("Origin"))
		if rec != nil {
			rec.SetError("Next.js refused this connection because it came from another site: " + r.Header.Get("Origin"))
		}
	case nextRefusedSocket(sw.err) && nextInternal(r.URL.Path):
		// The tunnel's own page was refused, and the agent may not or could
		// not help. Its pages try again every few seconds; say it once, with
		// the remedy.
		text := nextNotice(r.Host)
		if t.toldNext.CompareAndSwap(false, true) {
			a.notice(t, text)
		}
		if rec != nil {
			rec.SetError(text)
		}
	default:
		a.cfg.Logger.Warn("the local service did not answer", "tunnel", t.Name, "upstream", t.Upstream.String(), "err", sw.err)
		if rec != nil {
			rec.SetError(fmt.Sprintf("%s did not answer: %v", t.Upstream, sw.err))
		}
	}
	if rec != nil {
		rec.Finish(sw.status)
	}
	if a.cfg.OnRequest != nil {
		a.cfg.OnRequest(Request{
			Tunnel: t.Name, Method: r.Method, Path: r.URL.RequestURI(),
			Status: status, Duration: time.Since(started), Replay: replayOf != "",
		})
	}
}

// replay sends a captured request to the local service again. It returns as
// soon as the request is on its way; the result shows up in the inspector.
func (a *agent) replay(ex *inspector.Exchange) error {
	t := a.tunnels[ex.Tunnel]
	if t == nil {
		return fmt.Errorf("the tunnel %q is not open any more", ex.Tunnel)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	req, err := http.NewRequestWithContext(ctx, ex.Method, "http://"+ex.Host+ex.URL, bytes.NewReader(ex.RequestBody.Data))
	if err != nil {
		cancel()
		return err
	}
	req.Host = ex.Host
	req.Header = ex.RequestHeader.Clone()
	req.ContentLength = int64(len(ex.RequestBody.Data))
	req.RemoteAddr = "127.0.0.1:0"
	go func() {
		defer cancel()
		a.serve(t, &discardWriter{header: http.Header{}}, req, ex.ID)
	}()
	return nil
}

// discardWriter receives the response to a replay; the inspector has kept it.
type discardWriter struct{ header http.Header }

func (d *discardWriter) Header() http.Header         { return d.header }
func (d *discardWriter) Write(p []byte) (int, error) { return len(p), nil }
func (d *discardWriter) WriteHeader(int)             {}

// newUpstreamProxy builds the proxy from the tunnel to the local service.
// With switched set, a service that refuses the public hostname the way dev
// servers do gets its own name from then on, and switched hears of it once.
// With adopted set, a Next.js dev server that refuses the tunnel's own origin
// gets its own from then on, on its dev endpoints, and adopted hears of it.
func newUpstreamProxy(t *tunnel, switched, adopted func(publicHost string)) *httputil.ReverseProxy {
	var transport http.RoundTripper = &http.Transport{
		// Proxy stays nil on purpose: a local service is never reached
		// through the HTTPS_PROXY that the server connection may need.
		DialContext: (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
		// Local development sites commonly use self-signed certificates.
		// This hop stays on the developer's machine or network.
		TLSClientConfig:     &tls.Config{InsecureSkipVerify: true},
		MaxIdleConnsPerHost: 32,
		IdleConnTimeout:     time.Minute,
		DisableCompression:  true,
	}
	if adopted != nil {
		transport = &nextOrigin{base: transport, t: t, adopted: adopted}
	}
	if switched != nil {
		transport = &hostFallback{base: transport, t: t, switched: switched}
	}
	return &httputil.ReverseProxy{
		Transport: transport,
		Rewrite: func(pr *httputil.ProxyRequest) {
			pr.SetURL(t.Upstream)
			if !t.rewriteHost.Load() {
				pr.Out.Host = pr.In.Host
			}
			// The tunnel server set these from the real visitor connection.
			for _, h := range []string{"X-Forwarded-For", "X-Forwarded-Host", "X-Forwarded-Proto"} {
				if v := pr.In.Header.Values(h); len(v) > 0 {
					pr.Out.Header[h] = v
				}
			}
		},
		// The ErrorHandler below reports failures; the proxy's own log lines
		// would only repeat them on the developer's terminal.
		ErrorLog: log.New(io.Discard, "", 0),
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			// The visitor gets a page without the local address or the
			// error; serve reports both to the developer.
			if sw, ok := w.(*statusWriter); ok {
				sw.err = err
			}
			errorpage.Write(w, r, errorpage.LocalServiceDown())
		},
	}
}

// ParseUpstream turns what a user types into the URL of a local service:
// "8000", "localhost:8000", "mysite.test" or a full http(s) URL.
func ParseUpstream(target string) (*url.URL, error) {
	target = strings.TrimSpace(target)
	if port, err := strconv.Atoi(target); err == nil {
		if port < 1 || port > 65535 {
			return nil, fmt.Errorf("%d is not a valid port", port)
		}
		// "localhost" rather than 127.0.0.1: some dev servers listen on ::1 only.
		target = "localhost:" + target
	}
	if !strings.Contains(target, "://") {
		target = "http://" + target
	}
	u, err := url.Parse(target)
	if err != nil {
		return nil, err
	}
	if (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" {
		return nil, fmt.Errorf("%q is not a port, a host or an http(s) URL", target)
	}
	return u, nil
}

// agentEndpoint derives the WebSocket URL from the configured server address.
func agentEndpoint(server string) (string, error) {
	u, err := serverURL(server)
	if err != nil {
		return "", err
	}
	if u.Scheme == "https" {
		u.Scheme = "wss"
	} else {
		u.Scheme = "ws"
	}
	u.Path = proto.AgentPath
	return u.String(), nil
}

// HTTPBase returns the http(s) address of the configured server, without a
// path, for downloads.
func HTTPBase(server string) (string, error) {
	u, err := serverURL(server)
	if err != nil {
		return "", err
	}
	u.Path, u.RawQuery, u.Fragment = "", "", ""
	return u.String(), nil
}

// serverURL parses the configured server address. A missing scheme means https.
func serverURL(server string) (*url.URL, error) {
	if server == "" {
		return nil, errors.New("no tunnel server configured")
	}
	if !strings.Contains(server, "://") {
		server = "https://" + server
	}
	u, err := url.Parse(server)
	if err != nil {
		return nil, err
	}
	switch u.Scheme {
	case "https", "wss":
		u.Scheme = "https"
	case "http", "ws":
		u.Scheme = "http"
	default:
		return nil, fmt.Errorf("unsupported server scheme %q", u.Scheme)
	}
	return u, nil
}

func isLoopback(host string) bool {
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

// statusWriter records the response status. Unwrap lets the reverse proxy
// reach the underlying writer for flushing and connection upgrades.
type statusWriter struct {
	http.ResponseWriter
	status int
	// err is why the local service could not be reached, if it could not.
	err error
}

func (w *statusWriter) WriteHeader(code int) {
	w.status = code
	w.ResponseWriter.WriteHeader(code)
}

func (w *statusWriter) Write(b []byte) (int, error) {
	if w.status == 0 {
		w.status = http.StatusOK
	}
	return w.ResponseWriter.Write(b)
}

func (w *statusWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }
