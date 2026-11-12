package inspector

import (
	"embed"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
)

//go:embed ui
var uiFiles embed.FS

var errReplayUnavailable = errors.New("replay is not available")

// The header every request that changes something must carry. A page on
// another site cannot set it without the browser asking first, and nothing
// here answers that question, so such pages cannot replay or clear anything.
const actionHeader = "X-Exposehub-Inspector"

// Handler serves the inspector page and its API.
//
// The page shows captured requests, Authorization and Cookie headers
// included, so it only answers requests addressed to a loopback name: a page
// on another site that points its own hostname at 127.0.0.1 (DNS rebinding)
// gets 403 and reads nothing.
func Handler(s *Store) http.Handler {
	ui, _ := fs.Sub(uiFiles, "ui")
	mux := http.NewServeMux()
	mux.Handle("GET /", http.FileServerFS(ui))
	mux.HandleFunc("GET /api/tunnels", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, s.Tunnels())
	})
	mux.HandleFunc("GET /api/requests", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, s.List())
	})
	mux.HandleFunc("GET /api/requests/{id}", func(w http.ResponseWriter, r *http.Request) {
		e, ok := s.Get(r.PathValue("id"))
		if !ok {
			http.Error(w, "no such request; it may have been dropped to make room", http.StatusNotFound)
			return
		}
		writeJSON(w, e)
	})
	mux.HandleFunc("POST /api/requests/{id}/replay", func(w http.ResponseWriter, r *http.Request) {
		e, ok := s.Get(r.PathValue("id"))
		switch {
		case !ok:
			http.Error(w, "no such request; it may have been dropped to make room", http.StatusNotFound)
		case e.Upgraded:
			http.Error(w, "a WebSocket connection cannot be replayed", http.StatusConflict)
		case e.RequestBody.Truncated:
			http.Error(w, fmt.Sprintf("the request body was larger than the %d KiB kept, so it cannot be replayed exactly", BodyLimit>>10), http.StatusConflict)
		default:
			if err := s.Replay(e); err != nil {
				http.Error(w, err.Error(), http.StatusConflict)
				return
			}
			w.WriteHeader(http.StatusAccepted)
		}
	})
	mux.HandleFunc("DELETE /api/requests", func(w http.ResponseWriter, _ *http.Request) {
		s.Clear()
		w.WriteHeader(http.StatusNoContent)
	})
	mux.HandleFunc("GET /api/events", func(w http.ResponseWriter, r *http.Request) {
		events, stop := s.Subscribe()
		defer stop()
		w.Header().Set("Content-Type", "text/event-stream")
		w.Header().Set("Cache-Control", "no-cache")
		rc := http.NewResponseController(w)
		fmt.Fprint(w, ": connected\n\n")
		_ = rc.Flush()
		ping := time.NewTicker(20 * time.Second)
		defer ping.Stop()
		for {
			select {
			case <-r.Context().Done():
				return
			case <-ping.C:
				fmt.Fprint(w, ": ping\n\n")
			case sum := <-events:
				body, _ := json.Marshal(sum)
				fmt.Fprintf(w, "event: exchange\ndata: %s\n\n", body)
			}
			if rc.Flush() != nil {
				return
			}
		}
	})
	return guard(mux)
}

func guard(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !loopbackHost(r.Host) {
			http.Error(w, "the inspector only answers on 127.0.0.1 or localhost", http.StatusForbidden)
			return
		}
		if origin := r.Header.Get("Origin"); origin != "" {
			if u, err := url.Parse(origin); err != nil || u.Host != r.Host {
				http.Error(w, "requests from other sites are refused", http.StatusForbidden)
				return
			}
		}
		if r.Method != http.MethodGet && r.Method != http.MethodHead && r.Header.Get(actionHeader) != "1" {
			http.Error(w, "missing "+actionHeader+" header", http.StatusForbidden)
			return
		}
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("X-Frame-Options", "DENY")
		w.Header().Set("Referrer-Policy", "no-referrer")
		// Captured bodies are shown as text, never as markup; nothing is loaded from elsewhere.
		w.Header().Set("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'")
		next.ServeHTTP(w, r)
	})
}

func loopbackHost(hostport string) bool {
	host := hostport
	if h, _, err := net.SplitHostPort(hostport); err == nil {
		host = h
	}
	host = strings.Trim(strings.ToLower(host), "[]")
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	_ = json.NewEncoder(w).Encode(v)
}
