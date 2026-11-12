package e2e

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/inspector"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
)

// startInspected runs an agent whose requests are recorded in a store.
func startInspected(t *testing.T, h *harness, tun agent.Tunnel) (*inspector.Store, string) {
	t.Helper()
	store := inspector.NewStore()
	connected := make(chan []proto.TunnelInfo, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = agent.Run(ctx, agent.Config{
			Server: "ws://" + agentHost, Token: "alice-token", Version: "test", Logger: quiet,
			HTTPClient: &http.Client{Transport: pinned(h.addr, nil)},
			Tunnels:    []agent.Tunnel{tun},
			Inspector:  store,
			OnConnect:  func(i []proto.TunnelInfo) { connected <- i },
		})
	}()
	t.Cleanup(func() { cancel(); <-done })
	select {
	case infos := <-connected:
		return store, infos[0].ID
	case <-time.After(5 * time.Second):
		t.Fatal("agent did not connect")
		return nil, ""
	}
}

// waitDone waits until the store holds n finished exchanges.
func waitDone(t *testing.T, s *inspector.Store, n int) []inspector.Summary {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for {
		list := s.List()
		finished := 0
		for _, e := range list {
			if e.Done {
				finished++
			}
		}
		if finished >= n {
			return list
		}
		if time.Now().After(deadline) {
			t.Fatalf("%d of %d exchanges finished", finished, n)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestTheInspectorRecordsAndReplays(t *testing.T) {
	h := newHarness(t)
	var hits atomic.Int32
	seen := make(chan string, 4)
	tun := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		body, _ := io.ReadAll(r.Body)
		seen <- string(body)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"ok":true}`)
	}))
	store, host := startInspected(t, h, tun)

	resp, err := h.visitor.Post("http://"+host+"/webhooks/payment?attempt=1", "application/json", strings.NewReader(`{"amount":42}`))
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	<-seen

	first := waitDone(t, store, 1)[0]
	e, _ := store.Get(first.ID)
	if e.Method != "POST" || e.URL != "/webhooks/payment?attempt=1" || e.Status != 201 || e.Host != host {
		t.Fatalf("recorded %s %s -> %d on %s", e.Method, e.URL, e.Status, e.Host)
	}
	if string(e.RequestBody.Data) != `{"amount":42}` || string(e.ResponseBody.Data) != `{"ok":true}` {
		t.Errorf("bodies: %q / %q", e.RequestBody.Data, e.ResponseBody.Data)
	}
	if e.Remote != "127.0.0.1" {
		t.Errorf("remote = %q, want the visitor address the server passed on", e.Remote)
	}

	if err := store.Replay(e); err != nil {
		t.Fatal(err)
	}
	if body := <-seen; body != `{"amount":42}` {
		t.Errorf("the replay sent %q", body)
	}
	list := waitDone(t, store, 2)
	if list[0].ReplayOf != first.ID || list[0].Status != 201 {
		t.Errorf("newest exchange = %+v, want the replay of %s", list[0], first.ID)
	}
	if hits.Load() != 2 {
		t.Errorf("the local service was called %d times, want 2", hits.Load())
	}
}

func TestStreamsAndWebSocketsStillWorkWhileRecorded(t *testing.T) {
	h := newHarness(t)
	release := make(chan struct{})
	tun := upstream(t, "web", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
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
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprint(w, "data: one\n")
		w.(http.Flusher).Flush()
		<-release
		fmt.Fprint(w, "data: two\n")
	}))
	store, host := startInspected(t, h, tun)

	resp, err := h.visitor.Get("http://" + host + "/events")
	if err != nil {
		t.Fatal(err)
	}
	lines := bufio.NewReader(resp.Body)
	got := make(chan string, 1)
	go func() { line, _ := lines.ReadString('\n'); got <- line }()
	select {
	case line := <-got:
		if line != "data: one\n" {
			t.Fatalf("first event %q", line)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("recording held back a streamed response")
	}
	close(release)
	_, _ = io.ReadAll(resp.Body)
	resp.Body.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, _, err := websocket.Dial(ctx, "ws://"+host+"/ws", &websocket.DialOptions{HTTPClient: h.visitor})
	if err != nil {
		t.Fatalf("WebSocket through a recorded tunnel: %v", err)
	}
	_ = c.Write(ctx, websocket.MessageText, []byte("ping"))
	if _, data, err := c.Read(ctx); err != nil || string(data) != "ping" {
		t.Fatalf("echo: %q, %v", data, err)
	}
	c.Close(websocket.StatusNormalClosure, "")

	var ws inspector.Summary
	for _, s := range waitDone(t, store, 2) {
		if s.URL == "/ws" {
			ws = s
		}
	}
	if !ws.Upgraded || ws.Status != http.StatusSwitchingProtocols {
		t.Errorf("WebSocket recorded as %+v, want an upgrade with status 101", ws)
	}
	// The page refuses to replay a WebSocket.
	r := httptest.NewRequest("POST", "/api/requests/"+ws.ID+"/replay", nil)
	r.Host = "127.0.0.1:4040"
	r.Header.Set("X-Exposehub-Inspector", "1")
	w := httptest.NewRecorder()
	inspector.Handler(store).ServeHTTP(w, r)
	if w.Code != http.StatusConflict {
		t.Errorf("replaying a WebSocket through the page: %d, want 409", w.Code)
	}
}
