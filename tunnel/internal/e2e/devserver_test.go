package e2e

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
)

// The answers below are what Vite 8.3.8 and webpack-dev-server gave through a
// tunnel on 2026-11-12, both with status 403.
var hostRefusals = map[string]string{
	"Vite":               "Blocked request. This host (%q) is not allowed.\nTo allow this host, add %[1]q to `server.allowedHosts` in vite.config.js.",
	"webpack-dev-server": "Invalid Host header",
}

// devServer plays a dev server that serves only requests naming its own
// address and turns the rest away with refusal.
func devServer(tb testing.TB, refusal string, page http.Handler) (agent.Tunnel, *atomic.Int32) {
	tb.Helper()
	var own atomic.Value
	refused := &atomic.Int32{}
	tun := upstream(tb, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != own.Load().(string) {
			refused.Add(1)
			w.Header().Set("Content-Type", "text/plain")
			w.WriteHeader(http.StatusForbidden)
			if strings.Contains(refusal, "%") {
				fmt.Fprintf(w, refusal, hostWithoutPort(r.Host))
			} else {
				fmt.Fprint(w, refusal)
			}
			return
		}
		page.ServeHTTP(w, r)
	}))
	own.Store(tun.Upstream.Host)
	return tun, refused
}

func hostWithoutPort(host string) string {
	if i := strings.LastIndexByte(host, ':'); i >= 0 {
		return host[:i]
	}
	return host
}

func notice(tb testing.TB, a *runningAgent) string {
	tb.Helper()
	select {
	case text := <-a.notices:
		return text
	case <-time.After(5 * time.Second):
		tb.Fatal("the agent said nothing to the developer")
		return ""
	}
}

func noNotice(tb testing.TB, a *runningAgent) {
	tb.Helper()
	select {
	case text := <-a.notices:
		tb.Errorf("the agent told the developer %q", text)
	default:
	}
}

func TestADevServerThatRefusesThePublicNameGetsItsOwn(t *testing.T) {
	for name, refusal := range hostRefusals {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			tun, refused := devServer(t, refusal, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				body, _ := io.ReadAll(r.Body)
				fmt.Fprintf(w, "page for %s, forwarded host %s, body %q", r.Host, r.Header.Get("X-Forwarded-Host"), body)
			}))
			a := h.start(t, "alice-token", tun)

			// The very first visitor already gets the page, not the refusal.
			status, body := h.get(t, a.host(), "/")
			want := fmt.Sprintf("page for %s, forwarded host %s, body %q", tun.Upstream.Host, a.host(), "")
			if status != http.StatusOK || body != want {
				t.Fatalf("first request: got %d %q, want 200 %q", status, body, want)
			}
			text := notice(t, a)
			for _, part := range []string{tun.Upstream.String(), hostWithoutPort(a.host()), "X-Forwarded-Host", "preserve"} {
				if !strings.Contains(text, part) {
					t.Errorf("the notice %q does not mention %q", text, part)
				}
			}

			// From then on nothing is refused, requests with a body included.
			resp, err := h.visitor.Post("http://"+a.host()+"/save", "text/plain", strings.NewReader("hello"))
			if err != nil {
				t.Fatal(err)
			}
			posted, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			if resp.StatusCode != http.StatusOK || !strings.Contains(string(posted), `body "hello"`) {
				t.Errorf("POST after the switch: got %d %q", resp.StatusCode, posted)
			}
			if n := refused.Load(); n != 1 {
				t.Errorf("the dev server refused %d requests, want only the first", n)
			}
			noNotice(t, a)
		})
	}
}

func TestAHotReloadSocketThatIsRefusedFirstStillOpens(t *testing.T) {
	h := newHarness(t)
	tun, _ := devServer(t, hostRefusals["Vite"], http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := websocket.Accept(w, r, &websocket.AcceptOptions{InsecureSkipVerify: true})
		if err != nil {
			return
		}
		defer c.CloseNow()
		_ = c.Write(r.Context(), websocket.MessageText, []byte("connected"))
	}))
	a := h.start(t, "alice-token", tun)

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, _, err := websocket.Dial(ctx, "ws://"+a.host()+"/", &websocket.DialOptions{HTTPClient: h.visitor})
	if err != nil {
		t.Fatalf("the socket did not open: %v", err)
	}
	defer c.CloseNow()
	if _, got, err := c.Read(ctx); err != nil || string(got) != "connected" {
		t.Errorf("read %q, %v; want the server's greeting", got, err)
	}
}

// The body of a request cannot be sent twice, so the first request keeps the
// dev server's answer, whole, and the one after it goes through.
func TestARequestWithABodyKeepsTheRefusalAndTheNextGoesThrough(t *testing.T) {
	h := newHarness(t)
	tun, _ := devServer(t, hostRefusals["Vite"], http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, "saved")
	}))
	a := h.start(t, "alice-token", tun)

	post := func() (int, string) {
		resp, err := h.visitor.Post("http://"+a.host()+"/save", "text/plain", strings.NewReader("hello"))
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		body, _ := io.ReadAll(resp.Body)
		return resp.StatusCode, string(body)
	}
	status, body := post()
	want := fmt.Sprintf(hostRefusals["Vite"], hostWithoutPort(a.host()))
	if status != http.StatusForbidden || body != want {
		t.Errorf("first POST: got %d %q, want the dev server's own 403 %q", status, body, want)
	}
	notice(t, a)
	if status, body := post(); status != http.StatusOK || body != "saved" {
		t.Errorf("second POST: got %d %q, want 200 saved", status, body)
	}
}

// Only the dev servers' refusals switch a tunnel. An application's own 403
// reaches the visitor byte for byte and the public name stays.
func TestAnOrdinary403PassesUntouched(t *testing.T) {
	for _, forbidden := range []string{"", "no", "Forbidden: members only. " + strings.Repeat("x", 64<<10)} {
		h := newHarness(t)
		hosts := make(chan string, 4)
		a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			hosts <- r.Host
			w.WriteHeader(http.StatusForbidden)
			fmt.Fprint(w, forbidden)
		})))
		for range 2 {
			status, body := h.get(t, a.host(), "/")
			if status != http.StatusForbidden || body != forbidden {
				t.Errorf("got %d and %d bytes, want the application's 403 with its %d bytes", status, len(body), len(forbidden))
			}
			if got := <-hosts; got != a.host() {
				t.Errorf("Host = %q, want the public %q", got, a.host())
			}
		}
		noNotice(t, a)
	}
}

func TestPreserveKeepsThePublicNameEvenWhenRefused(t *testing.T) {
	h := newHarness(t)
	tun, refused := devServer(t, hostRefusals["webpack-dev-server"], echoPath())
	tun.HostHeader = agent.HostPreserve
	a := h.start(t, "alice-token", tun)

	for range 2 {
		if status, body := h.get(t, a.host(), "/"); status != http.StatusForbidden || body != "Invalid Host header" {
			t.Errorf("got %d %q, want the dev server's refusal", status, body)
		}
	}
	if n := refused.Load(); n != 2 {
		t.Errorf("the dev server refused %d requests, want both", n)
	}
	noNotice(t, a)
}

// nextDev plays the dev server of Next.js 16.4.6: pages under any name, but
// its dev endpoints only for requests without an Origin or with one it
// allows, which is localhost. The rest gets "Unauthorized": as a 403, or for
// an upgrade written to the connection in place of a response. origins
// receives the Origin of every request that got through to the application
// or to a dev endpoint.
func nextDev(tb testing.TB, origins chan<- string) agent.Tunnel {
	tb.Helper()
	return upstream(tb, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		origin := r.Header.Get("Origin")
		internal := strings.Contains(r.URL.Path, "/_next") || strings.Contains(r.URL.Path, "/__nextjs")
		allowed := origin == "" || strings.HasPrefix(origin, "http://localhost:")
		upgrade := r.Header.Get("Upgrade") != ""
		switch {
		case internal && !allowed && upgrade:
			conn, _, err := http.NewResponseController(w).Hijack()
			if err != nil {
				tb.Error(err)
				return
			}
			defer conn.Close()
			fmt.Fprint(conn, "Unauthorized")
		case internal && !allowed:
			w.WriteHeader(http.StatusForbidden)
			fmt.Fprint(w, "Unauthorized")
		case upgrade:
			origins <- origin
			c, err := websocket.Accept(w, r, &websocket.AcceptOptions{InsecureSkipVerify: true})
			if err != nil {
				return
			}
			defer c.CloseNow()
			_ = c.Write(r.Context(), websocket.MessageText, []byte("hot reload connected"))
		default:
			origins <- origin
			fmt.Fprint(w, "ok")
		}
	}))
}

// hotReload opens the hot-reload socket the way a page at origin does and
// returns the server's greeting, or the error.
func hotReload(h *harness, host, origin string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, _, err := websocket.Dial(ctx, "ws://"+host+"/_next/hmr?id=abc", &websocket.DialOptions{
		HTTPClient: h.visitor, HTTPHeader: http.Header{"Origin": {origin}},
	})
	if err != nil {
		return "", err
	}
	defer c.CloseNow()
	_, got, err := c.Read(ctx)
	return string(got), err
}

// A page opened through the tunnel is the dev server's own page, so its
// hot-reload socket and error-overlay calls reach Next.js as coming from the
// dev server's own address: hot reload works without touching next.config.js,
// from the first attempt. Pages of other sites keep their origin and stay
// refused, and the application's own routes see the real origin.
func TestNextJsHotReloadWorksForTheTunnelsOwnPages(t *testing.T) {
	for _, mode := range []string{agent.HostAuto, agent.HostRewrite} {
		h := newHarness(t)
		origins := make(chan string, 8)
		tun := nextDev(t, origins)
		tun.HostHeader = mode
		a := h.start(t, "alice-token", tun)
		own, local := "http://"+a.host(), "http://localhost:"+tun.Upstream.Port()

		if got, err := hotReload(h, a.host(), own); err != nil || got != "hot reload connected" {
			t.Fatalf("mode %q: the first hot-reload socket: %q, %v", mode, got, err)
		}
		if got := <-origins; got != local {
			t.Errorf("mode %q: Next.js saw the origin %q, want its own %q", mode, got, local)
		}
		text := notice(t, a)
		for _, part := range []string{"Next.js", tun.Upstream.String(), hostWithoutPort(a.host()), "preserve", "allowedDevOrigins"} {
			if !strings.Contains(text, part) {
				t.Errorf("mode %q: the notice %q does not mention %q", mode, text, part)
			}
		}

		// The error overlay posts to a dev endpoint; a body cannot be sent
		// twice, but by now nothing is refused in the first place.
		post := func(path, origin string) (int, string) {
			req, _ := http.NewRequest(http.MethodPost, "http://"+a.host()+path, strings.NewReader("{}"))
			req.Header.Set("Origin", origin)
			resp, err := h.visitor.Do(req)
			if err != nil {
				t.Fatal(err)
			}
			defer resp.Body.Close()
			body, _ := io.ReadAll(resp.Body)
			return resp.StatusCode, string(body)
		}
		if status, body := post("/__nextjs_original-stack-frames", own); status != http.StatusOK || body != "ok" {
			t.Errorf("mode %q: the overlay's call: got %d %q", mode, status, body)
		}
		if got := <-origins; got != local {
			t.Errorf("mode %q: the overlay's call arrived with origin %q, want %q", mode, got, local)
		}

		// Server Actions post to the application's own routes and compare the
		// origin with X-Forwarded-Host: it must arrive as the browser sent it.
		if status, _ := post("/checkout", own); status != http.StatusOK {
			t.Errorf("mode %q: the application's route answered %d", mode, status)
		}
		if got := <-origins; got != own {
			t.Errorf("mode %q: the application saw the origin %q, want the real %q", mode, got, own)
		}

		// Another site's page still gets nowhere, and the developer is not
		// told to allow anything: nothing is wrong.
		if got, err := hotReload(h, a.host(), "http://evil.example"); err == nil {
			t.Errorf("mode %q: another site's page opened the hot-reload socket and read %q", mode, got)
		}
		if status, body := post("/__nextjs_original-stack-frames", "http://evil.example"); status != http.StatusForbidden || body != "Unauthorized" {
			t.Errorf("mode %q: another site's call to a dev endpoint: got %d %q, want Next's own 403", mode, status, body)
		}
		// So does a site whose name merely starts with the tunnel's.
		if _, err := hotReload(h, a.host(), own+".evil.example"); err == nil {
			t.Errorf("mode %q: a look-alike origin opened the hot-reload socket", mode)
		}
		noNotice(t, a)
	}
}

// A request with a body that meets the refusal first keeps it, whole; the
// next one goes through.
func TestANextJsDevEndpointCallWithABodyKeepsTheFirstRefusal(t *testing.T) {
	h := newHarness(t)
	origins := make(chan string, 4)
	a := h.start(t, "alice-token", nextDev(t, origins))
	post := func() (int, string) {
		req, _ := http.NewRequest(http.MethodPost, "http://"+a.host()+"/__nextjs_original-stack-frames", strings.NewReader("{}"))
		req.Header.Set("Origin", "http://"+a.host())
		resp, err := h.visitor.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		body, _ := io.ReadAll(resp.Body)
		return resp.StatusCode, string(body)
	}
	if status, body := post(); status != http.StatusForbidden || body != "Unauthorized" {
		t.Errorf("first call: got %d %q, want Next's own 403", status, body)
	}
	notice(t, a)
	if status, body := post(); status != http.StatusOK || body != "ok" {
		t.Errorf("second call: got %d %q, want 200 ok", status, body)
	}
}

// With the host header set to preserve the developer asked for nothing to be
// changed. Hot reload stays refused, and they are told which line of
// next.config.js allows it, once, however often the page tries again.
func TestWithPreserveANextJsHotReloadRefusalIsExplainedOnce(t *testing.T) {
	h := newHarness(t)
	origins := make(chan string, 4)
	tun := nextDev(t, origins)
	tun.HostHeader = agent.HostPreserve
	a := h.start(t, "alice-token", tun)

	if status, body := h.get(t, a.host(), "/"); status != http.StatusOK || body != "ok" {
		t.Fatalf("the page: got %d %q", status, body)
	}
	for range 3 {
		if got, err := hotReload(h, a.host(), "http://"+a.host()); err == nil {
			t.Errorf("the hot-reload socket opened and read %q", got)
		}
	}
	text := notice(t, a)
	host := hostWithoutPort(a.host())
	for _, part := range []string{"Next.js", "allowedDevOrigins: ['" + host + "']", "next.config.js"} {
		if !strings.Contains(text, part) {
			t.Errorf("the notice %q does not mention %q", text, part)
		}
	}
	// Another site being refused is no reason to say anything more.
	_, _ = hotReload(h, a.host(), "http://evil.example")
	noNotice(t, a)
}

// An application that answers 403 "Unauthorized" on a path that merely looks
// like one of Next's is not a Next.js dev server refusing an origin unless
// the origin was the tunnel's own; and what it answers arrives unchanged.
func TestAnOrdinaryUnauthorizedAnswerPassesUntouched(t *testing.T) {
	h := newHarness(t)
	a := h.start(t, "alice-token", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		fmt.Fprint(w, "Unauthorized: sign in first, "+r.Header.Get("Origin"))
	})))
	for _, path := range []string{"/_next/data.json", "/api/private"} {
		req, _ := http.NewRequest(http.MethodGet, "http://"+a.host()+path, nil)
		req.Header.Set("Origin", "http://"+a.host())
		resp, err := h.visitor.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		body, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		if want := "Unauthorized: sign in first, http://" + a.host(); resp.StatusCode != http.StatusForbidden || string(body) != want {
			t.Errorf("%s: got %d %q, want the application's own answer %q", path, resp.StatusCode, body, want)
		}
	}
	noNotice(t, a)
}
