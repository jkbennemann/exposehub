package agent

import (
	"bytes"
	"context"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/jkbennemann/exposehub/tunnel/internal/inspector"
)

func TestParseUpstream(t *testing.T) {
	for in, want := range map[string]string{
		"8000":                   "http://localhost:8000",
		"localhost:3000":         "http://localhost:3000",
		"mysite.test":            "http://mysite.test",
		"https://mysite.test":    "https://mysite.test",
		"http://127.0.0.1:8080/": "http://127.0.0.1:8080/",
	} {
		u, err := ParseUpstream(in)
		if err != nil {
			t.Errorf("ParseUpstream(%q): %v", in, err)
			continue
		}
		if u.String() != want {
			t.Errorf("ParseUpstream(%q) = %q, want %q", in, u, want)
		}
	}
	for _, in := range []string{"", "0", "70000", "ftp://host", "http://"} {
		if u, err := ParseUpstream(in); err == nil {
			t.Errorf("ParseUpstream(%q) = %q, want an error", in, u)
		}
	}
}

func TestAgentEndpoint(t *testing.T) {
	for in, want := range map[string]string{
		"connect.example.eu":         "wss://connect.example.eu/_exposehub/agent",
		"https://connect.example.eu": "wss://connect.example.eu/_exposehub/agent",
		"http://localhost:8080":      "ws://localhost:8080/_exposehub/agent",
		"ws://localhost:8080/":       "ws://localhost:8080/_exposehub/agent",
	} {
		got, err := agentEndpoint(in)
		if err != nil || got != want {
			t.Errorf("agentEndpoint(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	if _, err := agentEndpoint(""); err == nil {
		t.Error("an empty server address must be an error")
	}
}

func TestHTTPBase(t *testing.T) {
	for in, want := range map[string]string{
		"connect.example.eu":             "https://connect.example.eu",
		"https://connect.example.eu/":    "https://connect.example.eu",
		"wss://connect.example.eu/x?y=1": "https://connect.example.eu",
		"http://localhost:8080":          "http://localhost:8080",
	} {
		got, err := HTTPBase(in)
		if err != nil || got != want {
			t.Errorf("HTTPBase(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
}

// Anyone on the internet can reach a tunnel whose local service is down. The
// page they get must not name the local address (which can hold values from
// the developer's environment) or the error; the developer's terminal and
// inspector get both.
func TestAVisitorIsNotToldWhereTheLocalServiceRunsOrWhyItFailed(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := ln.Addr().String()
	ln.Close() // nothing listens here any more
	up, err := ParseUpstream("http://" + addr + "/SECRET-FROM-ENV/")
	if err != nil {
		t.Fatal(err)
	}
	var logs bytes.Buffer
	store := inspector.NewStore()
	a := &agent{cfg: Config{Logger: slog.New(slog.NewTextHandler(&logs, nil)), Inspector: store}}
	tun := Tunnel{Name: "web", Upstream: up}
	served := a.newTunnel(tun)

	for _, accept := range []string{"text/html", "*/*"} {
		r := httptest.NewRequest(http.MethodGet, "http://demo.tunnel.test/x", nil)
		r.Header.Set("Accept", accept)
		w := httptest.NewRecorder()
		a.serve(served, w, r, "")
		body := w.Body.String()
		if w.Code != http.StatusBadGateway || !strings.Contains(body, "not answer") {
			t.Errorf("Accept %s: got %d %q, want the 502 page", accept, w.Code, body)
		}
		for _, leak := range []string{addr, "SECRET-FROM-ENV", "refused", "dial"} {
			if strings.Contains(body, leak) {
				t.Errorf("Accept %s: the visitor's page contains %q:\n%s", accept, leak, body)
			}
		}
	}

	if !strings.Contains(logs.String(), "SECRET-FROM-ENV") || !strings.Contains(logs.String(), addr) {
		t.Errorf("the terminal does not say which local service failed: %q", logs.String())
	}
	sums := store.List()
	if len(sums) != 2 {
		t.Fatalf("inspector has %d requests, want 2", len(sums))
	}
	ex, _ := store.Get(sums[0].ID)
	if !strings.Contains(ex.Error, addr) {
		t.Errorf("the inspector does not say which local service failed: %q", ex.Error)
	}
}

func TestReachableTellsAListeningPortFromAClosedOne(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	up, err := ParseUpstream(ln.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	if err := Reachable(context.Background(), up); err != nil {
		t.Errorf("a listening port counts as unreachable: %v", err)
	}
	ln.Close()
	if err := Reachable(context.Background(), up); err == nil {
		t.Error("a closed port counts as reachable")
	}
}
