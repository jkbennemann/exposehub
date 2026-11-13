package server

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"log/slog"
	"net/http/httptest"
	"testing"
	"time"
)

// Checks added by the security review of the demo, before its first release.

func TestOnlyTheDashboardsOwnTrueMarksADemoToken(t *testing.T) {
	c, err := NewControlPlane(ControlPlaneConfig{URL: "http://dashboard.test", Secret: "s"}, slog.New(slog.NewTextHandler(io.Discard, nil)))
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256([]byte("eh_x"))
	hash := hex.EncodeToString(sum[:])
	snapshot := func(demo string) []byte {
		return []byte(`{"tokens":[{"id":1,"hash":"` + hash + `","team_id":9,"team":"d"` + demo + `}]}`)
	}
	for demo, want := range map[string]bool{``: false, `,"demo":null`: false, `,"demo":false`: false, `,"demo":true`: true} {
		if err := c.apply(snapshot(demo)); err != nil {
			t.Fatalf("%q: %v", demo, err)
		}
		if p, ok := c.lookup(sum); !ok || p.Demo != want {
			t.Errorf("%q: known %v, demo %v, want demo %v", demo, ok, p.Demo, want)
		}
	}
	// Anything else is not a snapshot the dashboard writes: it is refused
	// whole, and the tokens known before stay as they were.
	if err := c.apply(snapshot(`,"demo":true`)); err != nil {
		t.Fatal(err)
	}
	for _, demo := range []string{`,"demo":"true"`, `,"demo":1`} {
		if err := c.apply(snapshot(demo)); err == nil {
			t.Errorf("%q was accepted", demo)
		}
		if p, ok := c.lookup(sum); !ok || !p.Demo {
			t.Errorf("%q changed the token that was known", demo)
		}
	}
}

func TestTheNoticeProofSurvivesOddInput(t *testing.T) {
	quiet := slog.New(slog.NewTextHandler(io.Discard, nil))
	s, err := New(Config{AgentHost: "connect.tunnel.test", TunnelDomains: []string{"tunnel.test"}}, StaticTokens{}, quiet)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	for _, proof := range []string{"", ".", "..", "+1.x", "-1.x", "99999999999999999999.x", "1e9.x", " 1.x", "\x00.\x00"} {
		if s.demoTokenValid("seen", "a.tunnel.test", "203.0.113.0", proof, now) {
			t.Errorf("%q was accepted", proof)
		}
	}
	// The button's address without its proof sets no cookie, whatever the method.
	for _, method := range []string{"GET", "HEAD", "POST", "OPTIONS", "PUT"} {
		r := httptest.NewRequest(method, "http://a.tunnel.test/.exposehub/demo-continue?to=%2Fx", nil)
		r.RemoteAddr = "203.0.113.9:1234"
		w := httptest.NewRecorder()
		if s.demoNoticed("a.tunnel.test", w, r) || w.Header().Get("Set-Cookie") != "" {
			t.Errorf("%s: the continue address let a visitor on without the notice", method)
		}
	}
}

func TestAnAllowanceBelongsToTheTokenAndIsForgottenOnceItIsFullAgain(t *testing.T) {
	quiet := slog.New(slog.NewTextHandler(io.Discard, nil))
	s, err := New(Config{AgentHost: "connect.tunnel.test", TunnelDomains: []string{"tunnel.test"}, DemoRequestsPerMinute: 2, DemoMegabytesPerMinute: 1}, StaticTokens{}, quiet)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	a := s.demoAllowance("7", now)
	a.requests.take(now)
	a.requests.take(now)
	if ok, _ := s.demoAllowance("7", now).requests.take(now); ok {
		t.Fatal("asking for the token's allowance again gave a fresh one")
	}
	if ok, _ := s.demoAllowance("8", now).requests.take(now); !ok {
		t.Fatal("another token shares the allowance")
	}

	// Bytes are counted after they passed; what went over is waited for.
	if wait := a.bytes.spend(1<<20, now); wait != 0 {
		t.Fatalf("a minute's worth of bytes had to wait %v", wait)
	}
	if wait := a.bytes.spend(1<<19, now); wait < 29*time.Second || wait > 31*time.Second {
		t.Fatalf("half a minute's worth over the allowance waits %v, want about 30s", wait)
	}

	// Kept while it could still hold something back, then forgotten.
	s.sweepAllowances(now.Add(time.Minute))
	if s.allowances["7"] != a {
		t.Fatal("the allowance was forgotten while it was still spent")
	}
	s.perToken["7"] = 1
	s.sweepAllowances(now.Add(time.Hour))
	if s.allowances["7"] != a {
		t.Fatal("the allowance of a token with an open session was forgotten")
	}
	delete(s.perToken, "7")
	s.sweepAllowances(now.Add(time.Hour))
	if len(s.allowances) != 0 {
		t.Fatalf("%d allowances are kept for tokens that are long gone", len(s.allowances))
	}
}
