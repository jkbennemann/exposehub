package server

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestABucketHoldsAMinutesWorthAndRefillsEvenly(t *testing.T) {
	start := time.Now()
	b := newBucket(6, start) // one request every ten seconds
	for i := range 6 {
		if ok, _ := b.take(start); !ok {
			t.Fatalf("request %d of the first six was refused", i+1)
		}
	}
	ok, wait := b.take(start)
	if ok || wait < 9*time.Second || wait > 10*time.Second {
		t.Errorf("the seventh request: allowed %v, wait %v; want a refusal and about ten seconds", ok, wait)
	}
	if ok, _ := b.take(start.Add(9 * time.Second)); ok {
		t.Error("a request was allowed a second early")
	}
	if ok, _ := b.take(start.Add(10 * time.Second)); !ok {
		t.Error("no request was allowed after ten seconds")
	}
	// An hour of quiet fills it up, and not beyond.
	later := start.Add(time.Hour)
	for i := range 6 {
		if ok, _ := b.take(later); !ok {
			t.Fatalf("after an hour, request %d was refused", i+1)
		}
	}
	if ok, _ := b.take(later); ok {
		t.Error("the bucket held more than a minute's worth")
	}
}

func TestOnlyPathsOnTheSameHostAreReturnedTo(t *testing.T) {
	for to, want := range map[string]string{
		"/":                                      "/",
		"/shop/cart?item=3&x=y":                  "/shop/cart?item=3&x=y",
		"/a%20b?q=%2F":                           "/a%20b?q=%2F",
		"/@evil.example":                         "/@evil.example",
		"":                                       "/",
		"shop":                                   "/",
		"//evil.example":                         "/",
		"//evil.example/path":                    "/",
		`/\evil.example`:                         "/",
		`/a\b`:                                   "/",
		"https://evil.example":                   "/",
		"javascript:alert(1)":                    "/",
		"/a\r\nSet-Cookie: x=1":                  "/",
		"/a\tb":                                  "/",
		"/a b":                                   "/",
		"/café":                                  "/",
		demoContinuePath + "?to=%2F&sig=1.x":     "/",
		"/" + strings.Repeat("a", demoMaxReturn): "/",
		"/" + strings.Repeat("a", demoMaxReturn-1):               "/" + strings.Repeat("a", demoMaxReturn-1),
		"/%2F%2Fevil.example":                                    "/%2F%2Fevil.example",
		"/.exposehub/other":                                      "/.exposehub/other",
		"/redirect?to=https://evil.example&next=//evil.example/": "/redirect?to=https://evil.example&next=//evil.example/",
	} {
		if got := localPath(to); got != want {
			t.Errorf("localPath(%q) = %q, want %q", to, got, want)
		}
	}
}

func TestTheNoticeCookieIsTakenOutOfARequest(t *testing.T) {
	for _, c := range []struct {
		in   []string
		want string
	}{
		{[]string{"a=1; " + demoCookie + "=17.abc; b=2"}, "a=1; b=2"},
		{[]string{demoCookie + "=17.abc"}, ""},
		{[]string{"a=1", demoCookie + "=x;b=2"}, "a=1; b=2"},
		{[]string{"a=1; b=2"}, "a=1; b=2"},
		{[]string{"x" + demoCookie + "=kept"}, "x" + demoCookie + "=kept"},
	} {
		r := httptest.NewRequest(http.MethodGet, "http://a.tunnel.test/", nil)
		for _, line := range c.in {
			r.Header.Add("Cookie", line)
		}
		stripCookie(r, demoCookie)
		if got := strings.Join(r.Header.Values("Cookie"), " | "); got != c.want {
			t.Errorf("%q became %q, want %q", c.in, got, c.want)
		}
	}
}

func TestWhatCountsAsOpeningAPage(t *testing.T) {
	req := func(method string, headers ...string) *http.Request {
		r := httptest.NewRequest(method, "http://a.tunnel.test/", nil)
		for i := 0; i < len(headers); i += 2 {
			r.Header.Set(headers[i], headers[i+1])
		}
		return r
	}
	for name, c := range map[string]struct {
		r    *http.Request
		want bool
	}{
		"a tab":                          {req("GET", "Sec-Fetch-Mode", "navigate", "Accept", "text/html"), true},
		"a form sent to the tunnel":      {req("POST", "Sec-Fetch-Mode", "navigate"), true},
		"a page's fetch":                 {req("GET", "Sec-Fetch-Mode", "cors", "Accept", "text/html"), false},
		"an image":                       {req("GET", "Sec-Fetch-Mode", "no-cors", "Accept", "image/*"), false},
		"a WebSocket":                    {req("GET", "Sec-Fetch-Mode", "websocket", "Upgrade", "websocket"), false},
		"a browser without the header":   {req("GET", "Accept", "text/html,application/xhtml+xml"), true},
		"its HEAD":                       {req("HEAD", "Accept", "text/html"), true},
		"its form, posted from any site": {req("POST", "Accept", "text/html,application/xhtml+xml", "Content-Type", "application/x-www-form-urlencoded"), true},
		"a webhook sending a form":       {req("POST", "Accept", "*/*", "Content-Type", "application/x-www-form-urlencoded"), false},
		"its WebSocket":                  {req("GET", "Accept", "text/html", "Upgrade", "websocket"), false},
		"curl":                           {req("GET", "Accept", "*/*"), false},
		"a webhook":                      {req("POST", "Content-Type", "application/json"), false},
	} {
		if got := isNavigation(c.r); got != c.want {
			t.Errorf("%s: navigation %v, want %v", name, got, c.want)
		}
	}
}

func TestTheProofOfANoticeIsBoundToTunnelVisitorPurposeAndTime(t *testing.T) {
	quiet := slog.New(slog.NewTextHandler(io.Discard, nil))
	cfg := Config{AgentHost: "connect.tunnel.test", TunnelDomains: []string{"tunnel.test"}}
	s, err := New(cfg, StaticTokens{}, quiet)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	token := s.demoToken("seen", "a.tunnel.test", "203.0.113.0", now.Add(time.Minute))
	valid := func(purpose, host, visitor, tok string, at time.Time) bool {
		return s.demoTokenValid(purpose, host, visitor, tok, at)
	}
	if !valid("seen", "a.tunnel.test", "203.0.113.0", token, now) {
		t.Fatal("a fresh proof was refused")
	}
	exp, mac, _ := strings.Cut(token, ".")
	for name, ok := range map[string]bool{
		"another tunnel":  valid("seen", "b.tunnel.test", "203.0.113.0", token, now),
		"another visitor": valid("seen", "a.tunnel.test", "198.51.100.0", token, now),
		"another purpose": valid("continue", "a.tunnel.test", "203.0.113.0", token, now),
		"after its time":  valid("seen", "a.tunnel.test", "203.0.113.0", token, now.Add(2*time.Minute)),
		"a later expiry":  valid("seen", "a.tunnel.test", "203.0.113.0", exp+"0."+mac, now),
		"no signature":    valid("seen", "a.tunnel.test", "203.0.113.0", exp+".", now),
		"the value 1":     valid("seen", "a.tunnel.test", "203.0.113.0", "1", now),
		"nothing":         valid("seen", "a.tunnel.test", "203.0.113.0", "", now),
	} {
		if ok {
			t.Errorf("a proof was accepted for %s", name)
		}
	}
	// Another server, or this one after a restart, has another key.
	other, _ := New(cfg, StaticTokens{}, quiet)
	if other.demoTokenValid("seen", "a.tunnel.test", "203.0.113.0", token, now) {
		t.Error("a proof signed with another key was accepted")
	}
}

func TestTheSnapshotSaysWhichTokensAreDemoTokens(t *testing.T) {
	c, err := NewControlPlane(ControlPlaneConfig{URL: "http://dashboard.test", Secret: "s"}, slog.New(slog.NewTextHandler(io.Discard, nil)))
	if err != nil {
		t.Fatal(err)
	}
	hash := func(token string) string {
		sum := sha256.Sum256([]byte(token))
		return hex.EncodeToString(sum[:])
	}
	body := `{"tokens":[` +
		`{"id":1,"hash":"` + hash("eh_demo") + `","team_id":9,"team":"demo","demo":true},` +
		`{"id":2,"hash":"` + hash("eh_team") + `","team_id":7,"team":"acme","demo":false},` +
		`{"id":3,"hash":"` + hash("eh_old") + `","team_id":7,"team":"acme"}]}`
	if err := c.apply([]byte(body)); err != nil {
		t.Fatal(err)
	}
	for token, want := range map[string]bool{"eh_demo": true, "eh_team": false, "eh_old": false} {
		p, ok := c.lookup(sha256.Sum256([]byte(token)))
		if !ok || p.Demo != want {
			t.Errorf("%s: known %v, demo %v; want demo %v", token, ok, p.Demo, want)
		}
	}
	// A static token is never a demo token, whatever its account is called.
	static, _ := ParseStaticTokens("demo:static-token")
	if p, err := static.Authenticate(context.Background(), "static-token"); err != nil || p.Demo {
		t.Errorf("a static token: demo %v, err %v", p.Demo, err)
	}
}

func TestOnlyTheServersOwnShapeCountsAsARandomName(t *testing.T) {
	for range 50 {
		if label := randomLabel(); !randomShaped(label) {
			t.Fatalf("%q, which the server made, does not count as random", label)
		}
	}
	for _, label := range []string{"", "shop", "paypal", "my-webshop", "abcdefghi", "abcdefghijk", "abcdefgh01", "ABCDEFGHIJ", "abcdefgh-i"} {
		if randomShaped(label) {
			t.Errorf("%q counts as a random name", label)
		}
	}
}

func TestADemoTokenGetsOnlyRandomNamesOneTunnelAndAShareOfTheCap(t *testing.T) {
	now := time.Now()
	r := newRegistry(time.Minute, "connect.tunnel.test")
	r.demoMax = 2
	demo := func(id string) Principal { return Principal{Account: "team:9", TokenID: id, Demo: true} }
	ask := func(sub string) []hostReq { return []hostReq{{domain: "tunnel.test", sub: sub}} }

	first := &session{}
	hosts, _, err := r.claim(demo("1"), first, ask("shop"), now)
	if err != nil || len(hosts) != 1 || hosts[0] == "shop.tunnel.test" || !randomShaped(strings.TrimSuffix(hosts[0], ".tunnel.test")) {
		t.Fatalf("a chosen name: hosts %v, err %v; want one random name", hosts, err)
	}
	mine := hosts[0]

	// The agent comes back and asks for what it had: same name, the old session displaced.
	again := &session{}
	hosts, displaced, err := r.claim(demo("1"), again, ask(strings.TrimSuffix(mine, ".tunnel.test")), now)
	if err != nil || len(hosts) != 1 || hosts[0] != mine || len(displaced) != 1 || displaced[0] != first {
		t.Fatalf("reconnect: hosts %v, displaced %d, err %v; want %s back", hosts, len(displaced), err, mine)
	}

	// The same token without a name: a new one, and the old tunnel goes.
	third := &session{}
	hosts, displaced, err = r.claim(demo("1"), third, ask(""), now)
	if err != nil || hosts[0] == mine || len(displaced) != 1 || displaced[0] != again {
		t.Fatalf("second tunnel of a token: hosts %v, displaced %d, err %v", hosts, len(displaced), err)
	}
	if sess, known := r.lookup(mine, now); sess != nil || known {
		t.Errorf("the token's first name is still held (session %v, known %v)", sess != nil, known)
	}

	// Another demo token cannot ask for a name a demo token holds.
	second := &session{}
	hosts, _, err = r.claim(demo("2"), second, ask(strings.TrimSuffix(hosts[0], ".tunnel.test")), now)
	if err != nil || hosts[0] == r.hostOf(third) {
		t.Fatalf("another token asked for a held name: hosts %v, err %v", hosts, err)
	}
	second.hosts = hosts

	// Two are open. A third token is turned away, the first two may reconnect.
	if _, _, err := r.claim(demo("3"), &session{}, ask(""), now); err == nil || !strings.Contains(err.Error(), "the demo is full right now (2 tunnels at once)") {
		t.Errorf("a third demo tunnel: %v, want the demo to be full", err)
	}
	if _, _, err := r.claim(demo("2"), second, ask(strings.TrimSuffix(second.hosts[0], ".tunnel.test")), now); err != nil {
		t.Errorf("a token with a tunnel was turned away by the cap: %v", err)
	}
	// A team's token is not counted and not capped.
	team := Principal{Account: "team:1", TokenID: "50"}
	for _, sub := range []string{"shop", "api", ""} {
		if _, _, err := r.claim(team, &session{}, ask(sub), now); err != nil {
			t.Errorf("a team's tunnel %q was refused: %v", sub, err)
		}
	}
	if hosts, _, _ := r.claim(team, &session{}, ask("blog"), now); hosts[0] != "blog.tunnel.test" {
		t.Errorf("a team asked for blog and got %v", hosts)
	}

	// A demo tunnel whose agent is away does not count.
	r.release(second, now)
	if _, _, err := r.claim(demo("3"), &session{}, ask(""), now); err != nil {
		t.Errorf("a demo tunnel was refused although one of the two is away: %v", err)
	}

	r.demoMax = 0
	if _, _, err := r.claim(demo("4"), &session{}, ask(""), now); !errors.Is(err, errDemoOff) {
		t.Errorf("with no demo tunnels allowed: %v", err)
	}
}

// hostOf returns the name sess holds, for tests.
func (r *registry) hostOf(sess *session) string {
	r.mu.RLock()
	defer r.mu.RUnlock()
	for host, e := range r.hosts {
		if e.sess == sess {
			return host
		}
	}
	return ""
}

func TestTheDemoSettingsAreReadAndChecked(t *testing.T) {
	env := map[string]string{
		"EXPOSEHUB_AGENT_HOST":     "connect.tunnel.example",
		"EXPOSEHUB_TUNNEL_DOMAINS": "tunnel.example",
	}
	read := func() (Config, error) { return ConfigFromEnv(func(k string) string { return env[k] }) }

	cfg, err := read()
	if err != nil || cfg.demoTunnels() != 20 || cfg.DemoRequestsPerMinute != 60 {
		t.Errorf("defaults: %d tunnels, %d requests a minute, err %v; want 20 and 60", cfg.demoTunnels(), cfg.DemoRequestsPerMinute, err)
	}
	env["EXPOSEHUB_DEMO_MAX_TUNNELS"], env["EXPOSEHUB_DEMO_REQUESTS_PER_MINUTE"] = "5", "120"
	if cfg, err = read(); err != nil || cfg.demoTunnels() != 5 || cfg.DemoRequestsPerMinute != 120 {
		t.Errorf("set: %d tunnels, %d requests a minute, err %v", cfg.demoTunnels(), cfg.DemoRequestsPerMinute, err)
	}
	env["EXPOSEHUB_DEMO_MAX_TUNNELS"] = "0"
	if cfg, err = read(); err != nil || cfg.demoTunnels() != 0 {
		t.Errorf("0: %d tunnels, err %v; want none", cfg.demoTunnels(), err)
	}
	// New normalizes once more, which must not turn "none" back into the default.
	if err := cfg.normalize(); err != nil || cfg.demoTunnels() != 0 {
		t.Errorf("0 after a second normalize: %d tunnels, err %v", cfg.demoTunnels(), err)
	}
	for name, bad := range map[string]string{
		"EXPOSEHUB_DEMO_MAX_TUNNELS":         "-1",
		"EXPOSEHUB_DEMO_REQUESTS_PER_MINUTE": "0",
	} {
		env["EXPOSEHUB_DEMO_MAX_TUNNELS"], env["EXPOSEHUB_DEMO_REQUESTS_PER_MINUTE"] = "", ""
		env[name] = bad
		if _, err := read(); err == nil || !strings.Contains(err.Error(), name) {
			t.Errorf("%s=%s: %v, want an error naming it", name, bad, err)
		}
	}
}
