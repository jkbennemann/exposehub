package e2e

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// waitForLogs waits until the fake dashboard holds at least n events.
func waitForLogs(t *testing.T, cp *fakeControlPlane, n int) []server.LogEvent {
	t.Helper()
	deadline := time.Now().Add(8 * time.Second)
	for {
		if got := cp.receivedLogs(); len(got) >= n {
			return got
		}
		if time.Now().After(deadline) {
			t.Fatalf("the dashboard received %d log events, want %d", len(cp.receivedLogs()), n)
		}
		time.Sleep(20 * time.Millisecond)
	}
}

// startTunnel opens one tunnel and returns its host and what the server said about logging.
func startTunnel(t *testing.T, h *harness, token string, tun agent.Tunnel) (string, string) {
	t.Helper()
	connected := make(chan []proto.TunnelInfo, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = agent.Run(ctx, agent.Config{
			Server: "ws://" + agentHost, Token: token, Version: "test", Logger: quiet,
			HTTPClient: &http.Client{Transport: pinned(h.addr, nil)},
			Tunnels:    []agent.Tunnel{tun},
			OnConnect:  func(i []proto.TunnelInfo) { connected <- i },
		})
	}()
	t.Cleanup(func() { cancel(); <-done })
	select {
	case infos := <-connected:
		return infos[0].ID, infos[0].Logging
	case <-time.After(5 * time.Second):
		t.Fatal("agent did not connect")
		return "", ""
	}
}

func shop(t *testing.T) agent.Tunnel {
	return upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		w.Header().Set("Set-Cookie", "session=abc123")
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		fmt.Fprintf(w, `{"echo":%q}`, body)
	}))
}

func TestRequestsAreLoggedAsSummariesByDefault(t *testing.T) {
	cp := newFakeControlPlane(t)
	id := cp.issue("eh_logged", 7, "acme")
	h := cp.connect(t, "")
	host, logging := startTunnel(t, h, "eh_logged", shop(t))
	if logging != "requests" {
		t.Errorf("the agent was told logging %q, want requests", logging)
	}

	req, _ := http.NewRequest("POST", "http://"+host+"/orders?page=2&access_token=s3cret&api_key=k&q=shoes", strings.NewReader(`{"card":"4242"}`))
	req.Header.Set("Authorization", "Bearer app-token")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()

	ev := waitForLogs(t, cp, 1)[0]
	if ev.TokenID != fmt.Sprint(id) || ev.Host != host || ev.Method != "POST" || ev.Status != 201 {
		t.Errorf("event %+v", ev)
	}
	if ev.Path != "/orders?page=2&access_token=[redacted]&api_key=[redacted]&q=shoes" {
		t.Errorf("path = %q, want secret-looking query values masked and the rest kept", ev.Path)
	}
	if ev.RequestBytes != int64(len(`{"card":"4242"}`)) || ev.ResponseBytes == 0 || ev.DurationMS <= 0 {
		t.Errorf("sizes %d/%d, duration %v", ev.RequestBytes, ev.ResponseBytes, ev.DurationMS)
	}
	if ev.Remote != "127.0.0.0" {
		t.Errorf("remote = %q, want the visitor address shortened to its network", ev.Remote)
	}
	if ev.Details != nil {
		t.Error("headers or bodies were logged although the tunnel did not ask for them")
	}
}

func TestBodiesAreLoggedOnlyWhenAskedAndSecretsAreBlanked(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_bodies", 7, "acme")
	h := cp.connect(t, "")
	tun := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body)
		w.Header().Set("Set-Cookie", "session=abc123")
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"echo":"received"}`)
	}))
	tun.LogBodies = true
	host, logging := startTunnel(t, h, "eh_bodies", tun)
	if logging != "bodies" {
		t.Errorf("the agent was told logging %q, want bodies", logging)
	}

	big := strings.Repeat("x", 100<<10)
	req, _ := http.NewRequest("POST", "http://"+host+"/upload", strings.NewReader(big))
	req.Header.Set("Authorization", "Bearer app-token")
	req.Header.Set("Cookie", "session=visitor")
	req.Header.Set("X-Api-Key", "k-123")
	req.Header.Set("X-Request-Id", "r-1")
	resp, err := h.visitor.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()

	d := waitForLogs(t, cp, 1)[0].Details
	if d == nil {
		t.Fatal("no details were logged")
	}
	for _, name := range []string{"Authorization", "Cookie", "X-Api-Key"} {
		if got := d.RequestHeader.Get(name); got != "[redacted]" {
			t.Errorf("request header %s = %q, want it blanked", name, got)
		}
	}
	if d.RequestHeader.Get("X-Request-Id") != "r-1" {
		t.Error("an ordinary header was blanked")
	}
	if d.ResponseHeader.Get("Set-Cookie") != "[redacted]" {
		t.Errorf("Set-Cookie = %q", d.ResponseHeader.Get("Set-Cookie"))
	}
	if len(d.RequestBody) != 64<<10 || !d.RequestTruncated {
		t.Errorf("request body: kept %d bytes, truncated %v; want 64 KiB and truncated", len(d.RequestBody), d.RequestTruncated)
	}
	if string(d.ResponseBody) != `{"echo":"received"}` || d.ResponseTruncated {
		t.Errorf("response body: %.30q, truncated %v", d.ResponseBody, d.ResponseTruncated)
	}
}

func TestNoLogAndStaticTokensLogNothing(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_quiet", 7, "acme")
	cp.issue("eh_witness", 7, "acme")
	h := cp.connect(t, "")

	quietTun := shop(t)
	quietTun.NoLog = true
	quietHost, logging := startTunnel(t, h, "eh_quiet", quietTun)
	if logging != "off" {
		t.Errorf("--no-log tunnel was told %q", logging)
	}
	witnessHost, _ := startTunnel(t, h, "eh_witness", shop(t))

	for _, host := range []string{quietHost, witnessHost} {
		resp, err := h.visitor.Get("http://" + host + "/")
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
	}
	// The witness's event arriving shows the quiet one had its chance too.
	logs := waitForLogs(t, cp, 1)
	time.Sleep(1200 * time.Millisecond)
	for _, ev := range append(logs, cp.receivedLogs()...) {
		if ev.Host == quietHost {
			t.Fatalf("a --no-log tunnel was logged: %+v", ev)
		}
	}
}

func TestRefusedVisitorsOfAProtectedTunnelAreLogged(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_locked", 7, "acme")
	h := cp.connect(t, "")
	tun := shop(t)
	tun.Auth = []string{"ada:pw"}
	host, _ := startTunnel(t, h, "eh_locked", tun)

	resp, err := h.visitor.Get("http://" + host + "/.env")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()

	ev := waitForLogs(t, cp, 1)[0]
	if ev.Path != "/.env" || ev.Status != http.StatusUnauthorized {
		t.Errorf("event %+v, want the refused request with 401", ev)
	}
}

func TestLogsAreRetriedWhileTheDashboardIsBusy(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_retry", 7, "acme")
	cp.mu.Lock()
	cp.failLogs = 2
	cp.mu.Unlock()
	h := cp.connect(t, "")
	host, _ := startTunnel(t, h, "eh_retry", shop(t))

	for i := range 3 {
		resp, err := h.visitor.Get(fmt.Sprintf("http://%s/try/%d", host, i))
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
	}
	if logs := waitForLogs(t, cp, 3); len(logs) != 3 {
		t.Errorf("got %d events after the dashboard recovered, want exactly 3", len(logs))
	}
}

// burst sends n requests to url(i) at once and waits for all of them. A
// request the loopback cannot take right now (macOS runs out of socket
// buffers with many large requests at once) is tried again.
func burst(t *testing.T, h *harness, n int, url func(i int) string) {
	t.Helper()
	var wg sync.WaitGroup
	for i := range n {
		wg.Add(1)
		go func() {
			defer wg.Done()
			var err error
			for range 5 {
				var resp *http.Response
				if resp, err = h.visitor.Get(url(i)); err == nil {
					io.Copy(io.Discard, resp.Body)
					resp.Body.Close()
					return
				}
				time.Sleep(50 * time.Millisecond)
			}
			t.Errorf("request %d: %.200v", i, err)
		}()
	}
	wg.Wait()
}

func TestLongPathsAreCutAndDoNotHoldUpOtherTeamsLogs(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_seven", 7, "seven")
	other := cp.issue("eh_eight", 8, "eight")
	h := cp.connect(t, "")
	seven, _ := startTunnel(t, h, "eh_seven", upstream(t, "web", echoPath()))
	eight, _ := startTunnel(t, h, "eh_eight", upstream(t, "web", echoPath()))

	// Anonymous visitors with request targets of almost 1 MB: together far
	// more than the dashboard takes in one batch.
	long := "/" + strings.Repeat("a", 900_000)
	burst(t, h, 80, func(int) string { return "http://" + seven + long })
	if status, _ := h.get(t, eight, "/after"); status != 200 {
		t.Fatalf("status %d", status)
	}

	logs := waitForLogs(t, cp, 81)
	delivered := false
	for _, ev := range logs {
		if len(ev.Path) > 2048 {
			t.Fatalf("a path of %d bytes was logged; the dashboard keeps 2048", len(ev.Path))
		}
		delivered = delivered || ev.TokenID == strconv.Itoa(other)
	}
	if !delivered {
		t.Error("the other team's event was not delivered")
	}
}

func TestBodiesModeLogsFitTheDashboardsBatchLimit(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_bodies", 7, "seven")
	h := cp.connect(t, "")
	page := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(strings.Repeat("b", 64<<10)))
	}))
	page.LogBodies = true
	host, _ := startTunnel(t, h, "eh_bodies", page)

	// 420 ordinary requests of a 64 KiB page come to more than 32 MiB of JSON.
	burst(t, h, 420, func(i int) string { return fmt.Sprintf("http://%s/page-%d", host, i) })
	if logs := waitForLogs(t, cp, 420); len(logs) != 420 {
		t.Errorf("the dashboard received %d events, want 420", len(logs))
	}
}
