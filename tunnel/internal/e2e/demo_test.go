package e2e

import (
	"context"
	"errors"
	"fmt"
	"html"
	"io"
	"net/http"
	"net/netip"
	"net/url"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// The demo team's id in these tests, and a team's.
const (
	demoTeam = 99
	acmeTeam = 7
)

// issueDemo adds a token of the demo account: the dashboard marks those.
func (f *fakeControlPlane) issueDemo(token string) int {
	id := f.issue(token, demoTeam, "demo")
	f.mu.Lock()
	defer f.mu.Unlock()
	f.tokens[len(f.tokens)-1]["demo"] = true
	return id
}

// demoServer starts a server whose dashboard knows three demo tokens and one
// of a team. The request limit is out of the way unless a test sets it.
func demoServer(t *testing.T, configure ...func(*server.Config)) (*fakeControlPlane, *harness) {
	t.Helper()
	cp := newFakeControlPlane(t)
	for _, token := range []string{"eh_demo1", "eh_demo2", "eh_demo3"} {
		cp.issueDemo(token)
	}
	cp.issue("eh_team", acmeTeam, "acme")
	h := cp.connect(t, "", append([]func(*server.Config){func(c *server.Config) { c.DemoRequestsPerMinute = 10000 }}, configure...)...)
	return cp, h
}

// refused starts an agent that the server must turn away, and returns why.
func refused(t *testing.T, h *harness, token string, tunnels ...agent.Tunnel) string {
	t.Helper()
	a, _ := h.launch(t, token, tunnels...)
	var fatal *agent.FatalError
	err := a.wait(t)
	if !errors.As(err, &fatal) {
		t.Fatalf("the agent returned %v, want a refusal", err)
	}
	return err.Error()
}

// browse sends a request the way a browser opens a page, without following
// redirects, and returns the response and its body.
func browse(t *testing.T, h *harness, method, rawURL string, headers ...string) (*http.Response, string) {
	t.Helper()
	req, err := http.NewRequest(method, rawURL, nil)
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8")
	req.Header.Set("Sec-Fetch-Mode", "navigate")
	req.Header.Set("Sec-Fetch-Dest", "document")
	for i := 0; i < len(headers); i += 2 {
		if headers[i+1] == "" {
			req.Header.Del(headers[i])
		} else {
			req.Header.Set(headers[i], headers[i+1])
		}
	}
	client := &http.Client{Transport: h.visitor.Transport, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	return resp, string(body)
}

var continueLink = regexp.MustCompile(`href="(/\.exposehub/demo-continue\?[^"]+)"`)

// noticeLink returns where the notice's button leads, or fails the test if
// body is not the notice.
func noticeLink(t *testing.T, body string) string {
	t.Helper()
	m := continueLink.FindStringSubmatch(body)
	if m == nil || !strings.Contains(body, "This is a demo tunnel") {
		t.Fatalf("not the notice of a demo tunnel:\n%.400s", body)
	}
	return html.UnescapeString(m[1])
}

// seen opens the notice of host and follows its button, and returns the
// cookie the browser holds afterwards.
func seen(t *testing.T, h *harness, host string, headers ...string) string {
	t.Helper()
	_, body := browse(t, h, "GET", "http://"+host+"/", headers...)
	resp, _ := browse(t, h, "GET", "http://"+host+noticeLink(t, body), headers...)
	if resp.StatusCode != http.StatusSeeOther || len(resp.Cookies()) != 1 {
		t.Fatalf("following the notice's button: status %d, cookies %v", resp.StatusCode, resp.Cookies())
	}
	c := resp.Cookies()[0]
	return c.Name + "=" + c.Value
}

func TestADemoTokenGetsARandomAddressAndOneTunnel(t *testing.T) {
	cp, h := demoServer(t)
	cp.connectDomain("dev.demo.example", demoTeam)

	named := upstream(t, "web", echoPath())
	named.Subdomain = "paypal"
	if why := refused(t, h, "eh_demo1", named); !strings.Contains(why, "random addresses only") || !strings.Contains(why, "--subdomain") {
		t.Errorf("a chosen subdomain: %q, want it refused with what to leave out", why)
	}
	if status, _ := h.get(t, "paypal."+tunnelDomain, "/"); status != http.StatusNotFound {
		t.Errorf("paypal.%s answers %d", tunnelDomain, status)
	}

	own := upstream(t, "web", echoPath())
	own.Hostname = "dev.demo.example"
	if why := refused(t, h, "eh_demo1", own); !strings.Contains(why, "random addresses only") || !strings.Contains(why, "--hostname") {
		t.Errorf("a domain of the demo team: %q, want it refused", why)
	}

	if why := refused(t, h, "eh_demo1", upstream(t, "web", echoPath()), upstream(t, "api", echoPath())); !strings.Contains(why, "one tunnel per token") {
		t.Errorf("two tunnels at once: %q, want them refused", why)
	}

	// A name in the shape of a random one is not a way around it: the agent
	// gets a name, but not that one.
	shaped := upstream(t, "web", echoPath())
	shaped.Subdomain = "abcdefghij"
	a := h.start(t, "eh_demo1", shaped)
	if a.host() == "abcdefghij."+tunnelDomain || !strings.HasSuffix(a.host(), "."+tunnelDomain) {
		t.Errorf("asked for abcdefghij and got %s", a.host())
	}
	if status, body := h.get(t, a.host(), "/ok"); status != 200 || body != "/ok" {
		t.Errorf("the demo tunnel answered %d %q", status, body)
	}
}

func TestADemoAgentKeepsItsAddressAcrossAReconnect(t *testing.T) {
	_, h := demoServer(t)
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	host := a.host()

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
			t.Fatal("the agent did not come back to its address")
		}
		time.Sleep(20 * time.Millisecond)
	}
}

func TestASecondAgentWithTheSameDemoTokenReplacesTheFirst(t *testing.T) {
	_, h := demoServer(t)
	a := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "first") })))
	b := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { fmt.Fprint(w, "second") })))

	var fatal *agent.FatalError
	if err := a.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "a demo token has one tunnel") {
		t.Errorf("the first agent returned %v, want to be told that the token has one tunnel", err)
	}
	if a.host() == b.host() {
		t.Fatalf("both agents got %s", a.host())
	}
	if status, _ := h.get(t, a.host(), "/"); status != http.StatusNotFound {
		t.Errorf("the first address answers %d, want 404: the token has one tunnel", status)
	}
	if status, body := h.get(t, b.host(), "/"); status != 200 || body != "second" {
		t.Errorf("the second address answered %d %q", status, body)
	}
}

func TestTheServerOpensOnlySoManyDemoTunnelsAtOnce(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) { c.DemoMaxTunnels = 2 })
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	h.start(t, "eh_demo2", upstream(t, "web", echoPath()))

	if why := refused(t, h, "eh_demo3", upstream(t, "web", echoPath())); !strings.Contains(why, "the demo is full right now (2 tunnels at once); try again in a few minutes") {
		t.Errorf("a third demo tunnel: %q, want the demo to be full", why)
	}
	// A team is not part of the demo's count.
	team := upstream(t, "web", echoPath())
	team.Subdomain = "shop"
	h.start(t, "eh_team", team, upstream(t, "api", echoPath()))

	// One of the two that are open may come back; the cap counts the others.
	a.conns.drop()
	deadline := time.Now().Add(10 * time.Second)
	for {
		if status, _ := h.get(t, a.host(), "/"); status == 200 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("a demo agent did not get back in while the demo was full")
		}
		time.Sleep(20 * time.Millisecond)
	}

	// Once one has gone, there is room again.
	a.stop()
	deadline = time.Now().Add(5 * time.Second)
	for {
		c, connected := h.launch(t, "eh_demo3", upstream(t, "web", echoPath()))
		select {
		case <-connected:
			return
		case <-c.done:
			if time.Now().After(deadline) || !strings.Contains(fmt.Sprint(c.err), "the demo is full") {
				t.Fatalf("after one demo tunnel closed, a new one was refused: %v", c.err)
			}
			time.Sleep(10 * time.Millisecond)
		case <-time.After(5 * time.Second):
			t.Fatal("agent did not connect")
		}
	}
}

func TestAServerCanOpenNoDemoTunnelsAtAll(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) { c.DemoMaxTunnels = -1 }) // what EXPOSEHUB_DEMO_MAX_TUNNELS=0 sets
	if why := refused(t, h, "eh_demo1", upstream(t, "web", echoPath())); !strings.Contains(why, "opens no demo tunnels") {
		t.Errorf("a demo tunnel: %q, want it refused", why)
	}
	h.start(t, "eh_team", upstream(t, "web", echoPath()))
}

func TestADemoTunnelAnswersOnlySoManyRequestsAMinute(t *testing.T) {
	cp, h := demoServer(t, func(c *server.Config) { c.DemoRequestsPerMinute = 5 })
	demo := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	team := h.start(t, "eh_team", upstream(t, "web", echoPath()))

	for i := range 5 {
		if status, _ := h.get(t, demo.host(), "/"); status != 200 {
			t.Fatalf("request %d of the first five: %d", i+1, status)
		}
	}
	resp, err := h.visitor.Get("http://" + demo.host() + "/sixth")
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != http.StatusTooManyRequests || !strings.Contains(string(body), "limited to 5 requests a minute") {
		t.Errorf("the sixth request: %d %q, want 429 naming the limit", resp.StatusCode, body)
	}
	if after := resp.Header.Get("Retry-After"); after == "" || after == "0" {
		t.Errorf("Retry-After = %q", after)
	}

	// A browser gets the page, and a WebSocket is one request like any other.
	page, pageBody := browse(t, h, "GET", "http://"+demo.host()+"/")
	if page.StatusCode != http.StatusTooManyRequests || !strings.Contains(pageBody, "<h1>This demo tunnel is at its limit</h1>") {
		t.Errorf("a browser over the limit: %d %.200q", page.StatusCode, pageBody)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	_, upgrade, err := websocket.Dial(ctx, "ws://"+demo.host()+"/ws", &websocket.DialOptions{HTTPClient: h.visitor})
	if err == nil || upgrade == nil || upgrade.StatusCode != http.StatusTooManyRequests {
		t.Errorf("a WebSocket over the limit: err %v, response %v", err, upgrade)
	}

	// The requests turned away are in the owner's log like other refusals.
	logged := false
	for _, ev := range waitForLogs(t, cp, 8) {
		if ev.Host == demo.host() && ev.Path == "/sixth" && ev.Status == http.StatusTooManyRequests {
			logged = true
		}
	}
	if !logged {
		t.Error("the request that was turned away is not in the log")
	}

	// A team's tunnel on the same server has no such limit.
	for i := range 30 {
		if status, _ := h.get(t, team.host(), "/"); status != 200 {
			t.Fatalf("request %d to a team's tunnel: %d", i+1, status)
		}
	}
}

func TestABrowserGetsTheNoticeBeforeADemoTunnelsFirstPage(t *testing.T) {
	_, h := demoServer(t)
	type hit struct{ method, uri, cookie string }
	hits := make(chan hit, 64)
	a := h.start(t, "eh_demo1", upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/ws" {
			c, err := websocket.Accept(w, r, nil)
			if err != nil {
				return
			}
			defer c.CloseNow()
			typ, data, err := c.Read(r.Context())
			if err == nil {
				_ = c.Write(r.Context(), typ, data)
			}
			return
		}
		hits <- hit{r.Method, r.URL.RequestURI(), r.Header.Get("Cookie")}
		// A service behind a demo tunnel must not be able to mark the notice as seen.
		w.Header().Add("Set-Cookie", "__exposehub_demo=1; Path=/; SameSite=None")
		w.Header().Add("Set-Cookie", "own=kept; Path=/")
		fmt.Fprint(w, "the page")
	})))
	base := "http://" + a.host()
	reached := func(what string) hit {
		t.Helper()
		select {
		case got := <-hits:
			return got
		case <-time.After(2 * time.Second):
			t.Fatalf("%s did not reach the service behind the tunnel", what)
			return hit{}
		}
	}
	untouched := func(what string) {
		t.Helper()
		select {
		case got := <-hits:
			t.Fatalf("%s reached the service behind the tunnel: %+v", what, got)
		default:
		}
	}

	// A browser opening a page gets the notice, and nothing reaches the service.
	resp, body := browse(t, h, "GET", base+"/shop/cart?item=3&x=%2F")
	link := noticeLink(t, body)
	untouched("a browser's first page")
	if resp.StatusCode != http.StatusOK || resp.Header.Get("Cache-Control") != "no-store" || resp.Header.Get("X-Robots-Tag") != "noindex" {
		t.Errorf("the notice: status %d, Cache-Control %q, X-Robots-Tag %q", resp.StatusCode, resp.Header.Get("Cache-Control"), resp.Header.Get("X-Robots-Tag"))
	}
	for _, want := range []string{agentHost, "without an account", "passwords or payment details", "Continue to the page"} {
		if !strings.Contains(body, want) {
			t.Errorf("the notice lacks %q", want)
		}
	}
	if strings.Contains(body, "<script") || len(resp.Cookies()) != 0 {
		t.Errorf("the notice carries a script or sets a cookie (%v)", resp.Cookies())
	}
	// So does a form another site sends to it, and a browser that does not say how it fetches.
	for what, r := range map[string][]string{
		"a form sent from another site":          {"POST", base + "/login"},
		"a browser without the Sec-Fetch header": {"GET", base + "/", "Sec-Fetch-Mode", "", "Sec-Fetch-Dest", ""},
	} {
		_, body := browse(t, h, r[0], r[1], r[2:]...)
		noticeLink(t, body)
		untouched(what)
	}

	// What is not a person opening a page passes: scripts, webhooks, a page's
	// own requests, WebSockets.
	if status, body := h.get(t, a.host(), "/plain"); status != 200 || body != "the page" {
		t.Errorf("a client that is not a browser: %d %q", status, body)
	}
	reached("a client that is not a browser")
	hook, err := h.visitor.Post(base+"/webhooks/payment", "application/json", strings.NewReader(`{"id":1}`))
	if err != nil || hook.StatusCode != 200 {
		t.Fatalf("a webhook: %v %v", hook, err)
	}
	hook.Body.Close()
	if got := reached("a webhook"); got.method != "POST" || got.uri != "/webhooks/payment" {
		t.Errorf("a webhook arrived as %+v", got)
	}
	if resp, _ := browse(t, h, "GET", base+"/api", "Sec-Fetch-Mode", "cors", "Sec-Fetch-Dest", "empty"); resp.StatusCode != 200 {
		t.Errorf("a page's own request: %d", resp.StatusCode)
	}
	reached("a page's own request")
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	ws, _, err := websocket.Dial(ctx, "ws://"+a.host()+"/ws", &websocket.DialOptions{HTTPClient: h.visitor})
	if err != nil {
		t.Fatalf("a WebSocket: %v", err)
	}
	_ = ws.Write(ctx, websocket.MessageText, []byte("ping"))
	if _, data, err := ws.Read(ctx); err != nil || string(data) != "ping" {
		t.Errorf("a WebSocket echoed %q, %v", data, err)
	}
	ws.CloseNow()

	// The button leads back to the address asked for, and leaves a cookie.
	resp, _ = browse(t, h, "GET", base+link)
	untouched("the notice's button")
	if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/shop/cart?item=3&x=%2F" {
		t.Fatalf("the button: status %d, Location %q; want 303 to the page asked for", resp.StatusCode, resp.Header.Get("Location"))
	}
	if len(resp.Cookies()) != 1 {
		t.Fatalf("the button set %d cookies", len(resp.Cookies()))
	}
	c := resp.Cookies()[0]
	if c.Name != "__exposehub_demo" || c.Path != "/" || c.MaxAge != 1800 || !c.HttpOnly || c.SameSite != http.SameSiteLaxMode || c.Domain != "" || c.Secure {
		t.Errorf("the cookie: %+v", c)
	}
	cookie := c.Name + "=" + c.Value

	// With it the page opens. The service does not get the cookie, and what
	// it sets under that name is not passed on.
	resp, body = browse(t, h, "GET", base+"/shop/cart?item=3", "Cookie", "sid=1; "+cookie+"; theme=dark")
	if resp.StatusCode != 200 || body != "the page" {
		t.Fatalf("with the cookie: %d %.100q", resp.StatusCode, body)
	}
	if got := reached("a browser that has seen the notice"); got.cookie != "sid=1; theme=dark" {
		t.Errorf("the service received the cookies %q, want its own only", got.cookie)
	}
	if got := resp.Header.Values("Set-Cookie"); len(got) != 1 || got[0] != "own=kept; Path=/" {
		t.Errorf("Set-Cookie = %q, want the service's own cookie only", got)
	}
	// The cookie the service tried to set is worth nothing either.
	_, body = browse(t, h, "GET", base+"/", "Cookie", "__exposehub_demo=1")
	noticeLink(t, body)
	untouched("a browser with a made-up cookie")

	// The button's address works only with the proof that came with the
	// notice: handed out bare, it leads to the notice.
	for _, bare := range []string{"/.exposehub/demo-continue?to=%2Flogin", "/.exposehub/demo-continue?to=%2Flogin&sig=9999999999.AAAAAAAAAAAAAAAAAAAAAA"} {
		resp, body := browse(t, h, "GET", base+bare)
		if got := noticeLink(t, body); !strings.Contains(got, "to=%2Flogin") || len(resp.Cookies()) != 0 {
			t.Errorf("%s: the notice leads on to %q and set %d cookies", bare, got, len(resp.Cookies()))
		}
		untouched("the button's address without its proof")
	}

	// It never leads to another site.
	u, _ := url.Parse(link)
	proof := u.Query().Get("sig")
	for _, to := range []string{"//evil.example", "//evil.example/login", `/\evil.example`, "https://evil.example", "evil.example", "/a\r\nX-Injected: 1", ""} {
		target := "/.exposehub/demo-continue?to=" + url.QueryEscape(to) + "&sig=" + url.QueryEscape(proof)
		resp, _ := browse(t, h, "GET", base+target)
		if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/" || resp.Header.Get("X-Injected") != "" {
			t.Errorf("to=%q: status %d, Location %q; want 303 to /", to, resp.StatusCode, resp.Header.Get("Location"))
		}
	}
	// With the cookie already there, the address just goes on.
	resp, _ = browse(t, h, "GET", base+"/.exposehub/demo-continue?to=%2Fnext", "Cookie", cookie)
	if resp.StatusCode != http.StatusSeeOther || resp.Header.Get("Location") != "/next" || len(resp.Cookies()) != 0 {
		t.Errorf("the button's address with the cookie: %d to %q, cookies %v", resp.StatusCode, resp.Header.Get("Location"), resp.Cookies())
	}
	untouched("the server's own path")
}

func TestTheNoticeCookieIsWorthNothingToAnotherVisitorOrOnAnotherTunnel(t *testing.T) {
	_, h := demoServer(t, func(c *server.Config) {
		c.TrustedProxies = []netip.Prefix{netip.MustParsePrefix("127.0.0.0/8")}
		c.PublicScheme, c.PublicPort = "https", "443"
	})
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	b := h.start(t, "eh_demo2", upstream(t, "web", echoPath()))
	const opener, victim = "203.0.113.9", "198.51.100.7"

	// The person who opened the tunnel clicks through their own notice.
	resp, body := browse(t, h, "GET", "http://"+a.host()+"/", "X-Forwarded-For", opener)
	link := noticeLink(t, body)
	resp, _ = browse(t, h, "GET", "http://"+a.host()+link, "X-Forwarded-For", opener)
	if resp.StatusCode != http.StatusSeeOther || len(resp.Cookies()) != 1 || !resp.Cookies()[0].Secure {
		t.Fatalf("the opener's own notice: status %d, cookies %+v; want a Secure cookie behind https", resp.StatusCode, resp.Cookies())
	}
	cookie := resp.Cookies()[0].Name + "=" + resp.Cookies()[0].Value
	if resp, _ := browse(t, h, "GET", "http://"+a.host()+"/in", "X-Forwarded-For", opener, "Cookie", cookie); resp.StatusCode != 200 {
		t.Fatalf("the opener with their cookie: %d", resp.StatusCode)
	}
	// A neighbour in the same network is the same visitor to the server.
	if resp, _ := browse(t, h, "GET", "http://"+a.host()+"/in", "X-Forwarded-For", "203.0.113.200", "Cookie", cookie); resp.StatusCode != 200 {
		t.Errorf("the same network with the cookie: %d", resp.StatusCode)
	}

	// Neither their cookie nor their button gets someone else past the notice.
	_, body = browse(t, h, "GET", "http://"+a.host()+"/login", "X-Forwarded-For", victim, "Cookie", cookie)
	noticeLink(t, body)
	resp, body = browse(t, h, "GET", "http://"+a.host()+link, "X-Forwarded-For", victim)
	noticeLink(t, body)
	if len(resp.Cookies()) != 0 {
		t.Errorf("another visitor following the opener's button got the cookie %v", resp.Cookies())
	}
	// And it is good for the one tunnel only.
	_, body = browse(t, h, "GET", "http://"+b.host()+"/", "X-Forwarded-For", opener, "Cookie", cookie)
	noticeLink(t, body)
}

func TestTheNoticeComesAfterTheNetworksAndBeforeThePassword(t *testing.T) {
	cp, h := demoServer(t)
	locked := upstream(t, "web", echoPath())
	locked.Auth = []string{"ada:pw"}
	a := h.start(t, "eh_demo1", locked)

	// First the notice, without a word about the password.
	resp, body := browse(t, h, "GET", "http://"+a.host()+"/")
	noticeLink(t, body)
	if resp.Header.Get("WWW-Authenticate") != "" {
		t.Error("a browser was asked for the password before it saw the notice")
	}
	// Then the password.
	cookie := seen(t, h, a.host())
	if resp, _ := browse(t, h, "GET", "http://"+a.host()+"/", "Cookie", cookie); resp.StatusCode != http.StatusUnauthorized {
		t.Errorf("after the notice, without the password: %d, want 401", resp.StatusCode)
	}
	req, _ := http.NewRequest("GET", "http://"+a.host()+"/in", nil)
	req.SetBasicAuth("ada", "pw")
	req.Header.Set("Cookie", cookie)
	if resp, err := h.visitor.Do(req); err != nil || resp.StatusCode != 200 {
		t.Errorf("after the notice, with the password: %v %v", resp, err)
	}

	// A visitor the networks keep out learns nothing about the tunnel.
	cp.setTeams(office(demoTeam, true, "203.0.113.0/24"))
	if got := eventually(t, h, a.host(), http.StatusForbidden); strings.Contains(got, "demo") {
		t.Errorf("a visitor from outside the networks was told: %q", got)
	}
	resp, body = browse(t, h, "GET", "http://"+a.host()+"/")
	if resp.StatusCode != http.StatusForbidden || strings.Contains(body, "This is a demo tunnel") {
		t.Errorf("a browser from outside the networks: %d %.200q", resp.StatusCode, body)
	}
}

func TestTheNoticeIsInTheOwnersLogWithoutItsProof(t *testing.T) {
	cp, h := demoServer(t)
	a := h.start(t, "eh_demo1", upstream(t, "web", echoPath()))
	seen(t, h, a.host())

	// The person who opened the tunnel sees that someone got the notice and
	// went on, but not the proof that got them past it, and not a second
	// copy of the address the visitor asked for.
	logs := waitForLogs(t, cp, 2)
	if logs[0].Path != "/" || logs[0].Status != http.StatusOK || logs[1].Status != http.StatusSeeOther {
		t.Errorf("the log has %+v and %+v, want the notice and the redirect", logs[0], logs[1])
	}
	if logs[1].Path != "/.exposehub/demo-continue?to=[redacted]&sig=[redacted]" {
		t.Errorf("the button's request was logged as %q", logs[1].Path)
	}
}

func TestADemoTunnelNeverKeepsBodies(t *testing.T) {
	cp, h := demoServer(t)
	tun := shop(t)
	tun.LogBodies = true
	host, logging := startTunnel(t, h, "eh_demo1", tun)
	if logging != "requests" {
		t.Errorf("a demo agent that asked for bodies was told logging %q, want requests", logging)
	}
	resp, err := h.visitor.Post("http://"+host+"/orders", "application/json", strings.NewReader(`{"card":"4242"}`))
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()

	ev := waitForLogs(t, cp, 1)[0]
	if ev.Host != host || ev.Path != "/orders" || ev.Status != http.StatusCreated {
		t.Errorf("event %+v", ev)
	}
	if ev.Details != nil {
		t.Errorf("headers or bodies of a demo tunnel were kept: %+v", ev.Details)
	}
}

func TestATeamsTunnelNextToTheDemoIsLeftAlone(t *testing.T) {
	cp, h := demoServer(t, func(c *server.Config) { c.DemoMaxTunnels, c.DemoRequestsPerMinute = 1, 2 })
	h.start(t, "eh_demo1", upstream(t, "web", echoPath()))

	paths := make(chan string, 64)
	shopTun := upstream(t, "shop", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths <- r.URL.RequestURI() + " " + r.Header.Get("Cookie")
		w.Header().Add("Set-Cookie", "__exposehub_demo=theirs; Path=/")
		fmt.Fprint(w, "the shop")
	}))
	shopTun.Subdomain = "shop"
	shopTun.LogBodies = true
	a := h.start(t, "eh_team", shopTun, upstream(t, "api", echoPath()))
	if a.host() != "shop."+tunnelDomain || len(a.tunnels) != 2 || a.tunnels[0].Logging != "bodies" {
		t.Fatalf("the team's agent got %+v", a.tunnels)
	}

	// A browser opens it without a notice, the server keeps none of its paths
	// or cookie names for itself, and it has no request limit.
	for i := range 10 {
		resp, body := browse(t, h, "GET", fmt.Sprintf("http://%s/.exposehub/demo-continue?to=//evil.example&n=%d", a.host(), i), "Cookie", "__exposehub_demo=mine")
		if resp.StatusCode != 200 || body != "the shop" {
			t.Fatalf("request %d: %d %.100q", i+1, resp.StatusCode, body)
		}
		if got := resp.Header.Get("Set-Cookie"); got != "__exposehub_demo=theirs; Path=/" {
			t.Fatalf("Set-Cookie = %q", got)
		}
		want := fmt.Sprintf("/.exposehub/demo-continue?to=//evil.example&n=%d __exposehub_demo=mine", i)
		if got := <-paths; got != want {
			t.Fatalf("the service received %q, want %q", got, want)
		}
	}
	if d := waitForLogs(t, cp, 10)[0].Details; d == nil {
		t.Error("a team's tunnel that asked for bodies has none in its log")
	}
}
