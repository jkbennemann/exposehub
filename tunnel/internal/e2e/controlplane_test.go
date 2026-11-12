package e2e

import (
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

const (
	nodeSecret       = "node-secret"
	maxLogBatchBytes = 32 << 20 // what the dashboard accepts
)

// fakeControlPlane serves the snapshot endpoint the way the web app does.
type fakeControlPlane struct {
	mu      sync.Mutex
	tokens  []map[string]any
	domains []map[string]any
	nextID  int
	ts      *httptest.Server

	logs     []server.LogEvent
	failLogs int // answer this many log batches with 503 first

	srv      *server.Server        // the server connect() started
	statuses []server.StatusReport // status reports received

	teams    []map[string]any // access rules per team
	reserved []map[string]any // saved subdomains

	disconnects []map[string]any // sessions somebody closed in the dashboard

	failSnapshots bool // answer snapshot requests with 500

	// Set before connect: how long the server trusts an old snapshot, and an
	// authenticator asked after the control plane.
	maxStale time.Duration
	also     server.Authenticator
}

// setFailSnapshots makes the snapshot endpoint fail, or work again.
func (f *fakeControlPlane) setFailSnapshots(fail bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.failSnapshots = fail
}

// setTeams replaces the teams' access rules the snapshot carries.
func (f *fakeControlPlane) setTeams(teams ...map[string]any) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.teams = teams
}

// reserve saves a subdomain hostname for a team.
func (f *fakeControlPlane) reserve(hostname string, teamID int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.reserved = append(f.reserved, map[string]any{"hostname": hostname, "team_id": teamID})
}

// disconnect asks for one session to be closed, as the dashboard does when
// somebody disconnects a tunnel there.
func (f *fakeControlPlane) disconnect(session string, tokenID int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.disconnects = append(f.disconnects, map[string]any{"session": session, "token_id": tokenID})
}

// receivedStatuses returns the status reports the fake has accepted so far.
func (f *fakeControlPlane) receivedStatuses() []server.StatusReport {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]server.StatusReport(nil), f.statuses...)
}

// receivedLogs returns the log events the fake has accepted so far.
func (f *fakeControlPlane) receivedLogs() []server.LogEvent {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]server.LogEvent(nil), f.logs...)
}

// connectDomain marks a custom hostname as verified for a team.
func (f *fakeControlPlane) connectDomain(hostname string, teamID int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.domains = append(f.domains, map[string]any{"hostname": hostname, "team_id": teamID})
}

func (f *fakeControlPlane) removeDomains() {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.domains = nil
}

func newFakeControlPlane(tb testing.TB) *fakeControlPlane {
	f := &fakeControlPlane{}
	f.ts = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/internal/v1/status" && r.Header.Get("Authorization") == "Bearer "+nodeSecret {
			var rep server.StatusReport
			if err := json.NewDecoder(r.Body).Decode(&rep); err != nil {
				http.Error(w, err.Error(), http.StatusBadRequest)
				return
			}
			f.mu.Lock()
			f.statuses = append(f.statuses, rep)
			f.mu.Unlock()
			w.WriteHeader(http.StatusNoContent)
			return
		}
		if r.URL.Path == "/internal/v1/logs" && r.Header.Get("Authorization") == "Bearer "+nodeSecret {
			f.mu.Lock()
			defer f.mu.Unlock()
			if f.failLogs > 0 {
				f.failLogs--
				http.Error(w, "busy", http.StatusServiceUnavailable)
				return
			}
			zr, err := gzip.NewReader(r.Body)
			if err != nil {
				http.Error(w, "not gzip", http.StatusBadRequest)
				return
			}
			// The dashboard refuses a batch above 32 MiB, decompressed.
			raw, err := io.ReadAll(io.LimitReader(zr, maxLogBatchBytes+1))
			if err != nil || len(raw) > maxLogBatchBytes {
				http.Error(w, "the batch is not readable or too large", http.StatusRequestEntityTooLarge)
				return
			}
			var batch struct{ Events []server.LogEvent }
			if err := json.Unmarshal(raw, &batch); err != nil {
				http.Error(w, err.Error(), http.StatusUnprocessableEntity)
				return
			}
			f.logs = append(f.logs, batch.Events...)
			w.WriteHeader(http.StatusAccepted)
			return
		}
		if r.URL.Path != "/internal/v1/snapshot" || r.Header.Get("Authorization") != "Bearer "+nodeSecret {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		f.mu.Lock()
		failing := f.failSnapshots
		body, _ := json.Marshal(map[string]any{"tokens": f.tokens, "domains": f.domains, "teams": f.teams, "reserved": f.reserved, "disconnects": f.disconnects})
		f.mu.Unlock()
		if failing {
			http.Error(w, `{"message":"Server Error"}`, http.StatusInternalServerError)
			return
		}
		sum := sha256.Sum256(body)
		etag := `"` + hex.EncodeToString(sum[:]) + `"`
		if r.Header.Get("If-None-Match") == etag {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("ETag", etag)
		w.Write(body)
	}))
	tb.Cleanup(f.ts.Close)
	return f
}

// issue adds a token for a team and returns its ID.
func (f *fakeControlPlane) issue(token string, teamID int, team string) int {
	sum := sha256.Sum256([]byte(token))
	f.mu.Lock()
	defer f.mu.Unlock()
	f.nextID++
	f.tokens = append(f.tokens, map[string]any{
		"id": f.nextID, "hash": hex.EncodeToString(sum[:]), "team_id": teamID, "team": team, "user_id": 1,
	})
	return f.nextID
}

func (f *fakeControlPlane) revoke(id int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	kept := f.tokens[:0]
	for _, t := range f.tokens {
		if t["id"] != id {
			kept = append(kept, t)
		}
	}
	f.tokens = kept
}

// connect starts a tunnel server that mirrors the fake control plane.
func (f *fakeControlPlane) connect(tb testing.TB, cacheFile string, configure ...func(*server.Config)) *harness {
	tb.Helper()
	f.mu.Lock()
	maxStale, also := f.maxStale, f.also
	f.mu.Unlock()
	cp, err := server.NewControlPlane(server.ControlPlaneConfig{
		URL: f.ts.URL, Secret: nodeSecret, CacheFile: cacheFile, Interval: 50 * time.Millisecond, MaxStale: maxStale,
	}, quiet)
	if err != nil {
		tb.Fatal(err)
	}
	var auth server.Authenticator = cp
	if also != nil {
		auth = server.Authenticators{cp, also}
	}
	h, srv := newHarnessWith(tb, auth, configure...)
	cp.Attach(srv)
	f.mu.Lock()
	f.srv = srv
	f.mu.Unlock()
	shipper := server.NewLogShipper(f.ts.URL, nodeSecret, quiet)
	srv.SetLogShipper(shipper)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		go shipper.Run(ctx)
		cp.Run(ctx)
	}()
	tb.Cleanup(func() {
		cancel()
		<-done
	})
	return h
}

func TestTokenFromControlPlaneOpensATunnel(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_first", 7, "acme")
	h := cp.connect(t, "")

	a := h.start(t, "eh_first", upstream(t, "web", echoPath()))
	if status, body := h.get(t, a.host(), "/ok"); status != 200 || body != "/ok" {
		t.Errorf("got %d %q", status, body)
	}

	b, _ := h.launch(t, "eh_unknown", upstream(t, "web", echoPath()))
	var fatal *agent.FatalError
	if err := b.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "token") {
		t.Errorf("agent with an unknown token returned %v, want a fatal token error", err)
	}
}

func TestTokenCreatedAfterStartWorksAtOnce(t *testing.T) {
	cp := newFakeControlPlane(t)
	h := cp.connect(t, "")

	cp.issue("eh_fresh", 7, "acme")

	a := h.start(t, "eh_fresh", upstream(t, "web", echoPath()))
	if status, _ := h.get(t, a.host(), "/"); status != 200 {
		t.Errorf("status %d", status)
	}
}

func TestRevokedTokenDisconnectsItsAgent(t *testing.T) {
	cp := newFakeControlPlane(t)
	id := cp.issue("eh_doomed", 7, "acme")
	cp.issue("eh_kept", 7, "acme")
	h := cp.connect(t, "")

	doomed := h.start(t, "eh_doomed", upstream(t, "web", echoPath()))
	kept := h.start(t, "eh_kept", upstream(t, "web", echoPath()))

	cp.revoke(id)

	var fatal *agent.FatalError
	if err := doomed.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "revoked") {
		t.Fatalf("agent returned %v, want a fatal notice that the token was revoked", err)
	}
	if status, _ := h.get(t, kept.host(), "/"); status != 200 {
		t.Errorf("the other token's tunnel answered %d, want it untouched", status)
	}

	again, _ := h.launch(t, "eh_doomed", upstream(t, "web", echoPath()))
	if err := again.wait(t); !errors.As(err, &fatal) {
		t.Errorf("reconnecting with the revoked token returned %v, want a fatal error", err)
	}
}

func TestKnownTokensSurviveAControlPlaneOutageAndARestart(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_cached", 7, "acme")
	cache := filepath.Join(t.TempDir(), "snapshot.json")

	first := cp.connect(t, cache)
	first.start(t, "eh_cached", upstream(t, "web", echoPath())).stop()

	cp.ts.Close() // the control plane is down from here on

	// A tunnel server started now has only the cache file to go on.
	second := cp.connect(t, cache)
	a := second.start(t, "eh_cached", upstream(t, "web", echoPath()))
	if status, _ := second.get(t, a.host(), "/"); status != 200 {
		t.Errorf("status %d, want the cached token to work while the control plane is down", status)
	}
}

func TestTeamServesItsOwnDomain(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_acme", 7, "acme")
	cp.issue("eh_other", 8, "other")
	cp.connectDomain("Dev.Acme.Example", 7)
	h := cp.connect(t, "")

	tun := upstream(t, "web", echoPath())
	tun.Hostname = "dev.acme.example"
	a := h.start(t, "eh_acme", tun)
	if got, want := a.tunnels[0].URL, "http://dev.acme.example"; got != want {
		t.Errorf("tunnel URL = %q, want %q", got, want)
	}
	if status, body := h.get(t, "dev.acme.example", "/hi"); status != 200 || body != "/hi" {
		t.Errorf("got %d %q", status, body)
	}

	// Another team cannot serve it, connected or not.
	theirs := upstream(t, "web", echoPath())
	theirs.Hostname = "dev.acme.example"
	b, _ := h.launch(t, "eh_other", theirs)
	var fatal *agent.FatalError
	if err := b.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "not a connected domain") {
		t.Errorf("other team's agent returned %v, want a refusal", err)
	}

	// Nor may anyone serve a domain nobody has connected.
	stray := upstream(t, "web", echoPath())
	stray.Hostname = "bank.example"
	c, _ := h.launch(t, "eh_acme", stray)
	if err := c.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "not a connected domain") {
		t.Errorf("agent asking for an unconnected domain returned %v, want a refusal", err)
	}
}

func TestRemovingADomainClosesItsTunnel(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_acme", 7, "acme")
	cp.connectDomain("dev.acme.example", 7)
	h := cp.connect(t, "")

	custom := upstream(t, "web", echoPath())
	custom.Hostname = "dev.acme.example"
	onDomain := h.start(t, "eh_acme", custom)
	plain := h.start(t, "eh_acme", upstream(t, "web", echoPath()))

	cp.removeDomains()

	var fatal *agent.FatalError
	if err := onDomain.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "domain was removed") {
		t.Fatalf("agent returned %v, want a fatal notice that the domain was removed", err)
	}
	if status, _ := h.get(t, plain.host(), "/"); status != 200 {
		t.Errorf("the team's other tunnel answered %d, want it untouched", status)
	}
}

func TestStaticTokensCannotUseCustomDomains(t *testing.T) {
	h := newHarness(t)
	tun := upstream(t, "web", echoPath())
	tun.Hostname = "dev.acme.example"
	a, _ := h.launch(t, "alice-token", tun)
	var fatal *agent.FatalError
	if err := a.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "custom domains") {
		t.Errorf("agent returned %v, want a refusal saying custom domains are not offered", err)
	}
}

func TestConnectedDomainsCarryTheConfiguredHeaders(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_acme", 7, "acme")
	cp.connectDomain("dev.acme.example", 7)
	cp.connectDomain("idle.acme.example", 7)
	h := cp.connect(t, "", func(cfg *server.Config) {
		cfg.CustomDomainHeaders = map[string]string{"X-Hsts-Host-Only": "1"}
	})

	custom := upstream(t, "web", echoPath())
	custom.Hostname = "dev.acme.example"
	custom.Auth = []string{"ada:pw"}
	h.start(t, "eh_acme", custom)
	plain := h.start(t, "eh_acme", upstream(t, "web", echoPath()))

	marker := func(host string, authorize bool) (int, string) {
		req, _ := http.NewRequest("GET", "http://"+host+"/", nil)
		if authorize {
			req.SetBasicAuth("ada", "pw")
		}
		resp, err := h.visitor.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
		return resp.StatusCode, resp.Header.Get("X-Hsts-Host-Only")
	}

	for name, c := range map[string]struct {
		host      string
		authorize bool
		status    int
		marked    bool
	}{
		"a proxied response on a connected domain":   {"dev.acme.example", true, 200, true},
		"the server's own 401 on a connected domain": {"dev.acme.example", false, 401, true},
		"a connected domain with no agent":           {"idle.acme.example", false, 404, true},
		"a subdomain of the tunnel domain":           {plain.host(), false, 200, false},
		"a host nobody has connected":                {"stranger.example", false, 404, false},
	} {
		status, got := marker(c.host, c.authorize)
		if status != c.status || (got != "") != c.marked {
			t.Errorf("%s: status %d, marker %q; want status %d, marked %v", name, status, got, c.status, c.marked)
		}
	}
}

func TestAStaleTokenListStopsDashboardTokensButNotStaticOnes(t *testing.T) {
	cp := newFakeControlPlane(t)
	doomed := cp.issue("eh_doomed", 7, "acme")
	cp.issue("eh_kept", 7, "acme")
	static, err := server.ParseStaticTokens("ops:static-token")
	if err != nil {
		t.Fatal(err)
	}
	cp.maxStale, cp.also = 500*time.Millisecond, static
	h := cp.connect(t, "")

	dash := h.start(t, "eh_doomed", upstream(t, "web", echoPath()))
	ops := h.start(t, "static-token", upstream(t, "web", echoPath()))

	// The dashboard keeps failing, for example because one team made its
	// snapshot too big to build, and meanwhile the token is revoked.
	cp.setFailSnapshots(true)
	cp.revoke(doomed)

	deadline := time.Now().Add(5 * time.Second)
	for {
		if status, _ := h.get(t, dash.host(), "/"); status != http.StatusOK {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("a dashboard token's tunnel kept working on a token list that could not be refreshed")
		}
		time.Sleep(20 * time.Millisecond)
	}
	if rep := server.NewStatusReporter(cp.srv, cp.ts.URL, nodeSecret, "9.9.9", quiet).Report(); !rep.TokensStale || rep.TokensRefreshedAt == nil {
		t.Errorf("status report: stale %v, refreshed at %v; want it to say the token list is stale", rep.TokensStale, rep.TokensRefreshedAt)
	}
	if status, _ := h.get(t, ops.host(), "/"); status != http.StatusOK {
		t.Errorf("a static token's tunnel answered %d, want it untouched", status)
	}

	// A dashboard token is refused for now, but not for good.
	waiting, connected := h.launch(t, "eh_kept", upstream(t, "web", echoPath()))
	select {
	case <-connected:
		t.Error("a dashboard token opened a tunnel on a stale token list")
	case <-waiting.done:
		t.Errorf("the agent gave up (%v); it should retry until the dashboard answers", waiting.err)
	case <-time.After(1500 * time.Millisecond):
	}
	waiting.stop()

	// Once the dashboard answers, the revocation lands and other tokens work.
	cp.setFailSnapshots(false)
	h.start(t, "eh_kept", upstream(t, "web", echoPath()))
	again, _ := h.launch(t, "eh_doomed", upstream(t, "web", echoPath()))
	var fatal *agent.FatalError
	if err := again.wait(t); !errors.As(err, &fatal) {
		t.Errorf("the revoked token returned %v, want a fatal error", err)
	}
}

func TestTokenRevokedDuringHandshakeCannotOpenTunnel(t *testing.T) {
	cp := newFakeControlPlane(t)
	id := cp.issue("eh_slow", 7, "acme")
	h := cp.connect(t, "")

	// Authenticated, control stream open, Hello held back.
	_, ctrl := rawAgent(t, h, "eh_slow")

	cp.revoke(id)
	deadline := time.Now().Add(5 * time.Second)
	for {
		ws, status := dialAgent(t, h, "eh_slow")
		if ws != nil {
			ws.CloseNow()
		}
		if status == http.StatusUnauthorized {
			break // the server knows the token is revoked
		}
		if time.Now().After(deadline) {
			t.Fatal("the revocation never reached the server")
		}
		time.Sleep(20 * time.Millisecond)
	}

	welcome := handshake(t, ctrl, proto.Hello{AgentVersion: "test", Tunnels: []proto.TunnelRequest{
		{Name: "web", Proto: proto.ProtoHTTP, Subdomain: "held", NoLog: true},
	}})
	if !strings.Contains(welcome.Error, "revoked") || len(welcome.Tunnels) != 0 {
		t.Errorf("welcome = %+v, want a refusal saying the token was revoked", welcome)
	}
	if status, _ := h.get(t, "held."+tunnelDomain, "/"); status == http.StatusOK {
		t.Error("the revoked token's tunnel is served")
	}
}

// sessionOf returns the id the status report gives the session serving host.
func sessionOf(tb testing.TB, srv *server.Server, host string) string {
	tb.Helper()
	for _, tun := range srv.Tunnels() {
		for _, h := range tun.Hosts {
			if h == host {
				if len(tun.ID) < 32 {
					tb.Fatalf("the session id %q is short enough to guess", tun.ID)
				}
				return tun.ID
			}
		}
	}
	tb.Fatalf("no session serves %s", host)
	return ""
}

// Someone who left a tunnel running on another machine closes it from the
// dashboard. That agent stops for good; the token and every other tunnel
// stay as they are.
func TestASessionClosedInTheDashboardEndsOnlyThatAgent(t *testing.T) {
	cp := newFakeControlPlane(t)
	forgotten := cp.issue("eh_home", 7, "acme")
	cp.issue("eh_laptop", 7, "acme")
	h := cp.connect(t, "")

	home := h.start(t, "eh_home", upstream(t, "web", echoPath()))
	laptop := h.start(t, "eh_laptop", upstream(t, "web", echoPath()))

	cp.disconnect(sessionOf(t, cp.srv, home.host()), forgotten)

	var fatal *agent.FatalError
	if err := home.wait(t); !errors.As(err, &fatal) || !strings.Contains(err.Error(), "disconnected in the dashboard") {
		t.Fatalf("agent returned %v, want a fatal notice that it was disconnected in the dashboard", err)
	}
	if status, _ := h.get(t, laptop.host(), "/"); status != 200 {
		t.Errorf("the other tunnel answered %d, want it untouched", status)
	}

	// The token still works: the same machine can open a tunnel again, and
	// the request that closed the old session does not close the new one.
	again := h.startWhenFree(t, "eh_home", upstream(t, "web", echoPath()))
	cp.issue("eh_new", 7, "acme") // any change makes the server apply the snapshot again
	time.Sleep(300 * time.Millisecond)
	if status, _ := h.get(t, again.host(), "/"); status != 200 {
		t.Errorf("the new tunnel of the same token answered %d", status)
	}
}

// The dashboard names the token it saw a session under. A request that names
// another token closes nothing, whatever the dashboard got wrong.
func TestADisconnectNamingAnotherTokenClosesNothing(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_mine", 7, "acme")
	other := cp.issue("eh_other", 8, "globex")
	h := cp.connect(t, "")
	mine := h.start(t, "eh_mine", upstream(t, "web", echoPath()))

	cp.disconnect(sessionOf(t, cp.srv, mine.host()), other)
	cp.disconnect("", other)
	time.Sleep(300 * time.Millisecond)

	if status, _ := h.get(t, mine.host(), "/"); status != 200 {
		t.Errorf("the tunnel answered %d, want it untouched", status)
	}
	select {
	case <-mine.done:
		t.Errorf("the agent stopped: %v", mine.err)
	default:
	}
}
