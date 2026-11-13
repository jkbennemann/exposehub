package main

import (
	"encoding/json"
	"errors"
	"io"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
)

// The --json output: one JSON object per line on stdout, each with an
// "event" field. Programs and coding agents read the first "ready" line for
// the public addresses; an "error" line is the last one before the agent
// stops. Fields are only ever added, never renamed.

// errNotLoggedIn is returned when no token is at hand.
var errNotLoggedIn = errors.New("not logged in; run: exposehub login")

// tunnelJSON describes one open tunnel.
type tunnelJSON struct {
	Name string `json:"name"`
	URL  string `json:"url"`
	// Target is what the tunnel forwards to: a URL, or for a folder the
	// folder's path.
	Target    string `json:"target"`
	Folder    bool   `json:"folder,omitempty"`
	Protected bool   `json:"protected"`
	Access    string `json:"access,omitempty"`
	Logging   string `json:"logging,omitempty"`
}

type readyEvent struct {
	Event     string       `json:"event"`
	Tunnels   []tunnelJSON `json:"tunnels"`
	Inspector string       `json:"inspector,omitempty"`
}

type messageEvent struct {
	Event   string `json:"event"`
	Name    string `json:"name,omitempty"`
	Target  string `json:"target,omitempty"`
	Message string `json:"message"`
}

type requestEvent struct {
	Event      string  `json:"event"`
	Time       string  `json:"time"`
	Name       string  `json:"name"`
	Method     string  `json:"method"`
	Path       string  `json:"path"`
	Status     int     `json:"status"`
	DurationMS float64 `json:"duration_ms"`
	Replay     bool    `json:"replay,omitempty"`
}

type updateEvent struct {
	Event   string `json:"event"`
	Version string `json:"version"`
	Current string `json:"current"`
}

type errorEvent struct {
	Event string `json:"event"`
	// Code is "not_logged_in" when the person has to log in first, and
	// "error" for anything else.
	Code    string `json:"code"`
	Message string `json:"message"`
}

// jsonWriter writes events one per line; callbacks of several tunnels may
// write at once.
type jsonWriter struct {
	mu  sync.Mutex
	enc *json.Encoder
}

func newJSONWriter(w io.Writer) *jsonWriter {
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	return &jsonWriter{enc: enc}
}

func (j *jsonWriter) write(v any) {
	j.mu.Lock()
	defer j.mu.Unlock()
	_ = j.enc.Encode(v)
}

// targetString is what a tunnel forwards to, as the person knows it.
func targetString(t agent.Tunnel) string {
	if t.Folder != "" {
		return t.Folder
	}
	return upstreamString(t.Upstream)
}

func describeTunnel(info proto.TunnelInfo, t agent.Tunnel) tunnelJSON {
	return tunnelJSON{
		Name: info.Name, URL: info.URL, Target: targetString(t), Folder: t.Folder != "",
		Protected: info.Protected, Access: info.Access, Logging: info.Logging,
	}
}

// maxEventPath is how much of a request's path an event carries. Visitors
// choose paths, up to the server's header limit of about a megabyte, and the
// events end up in a program's or a model's input.
const maxEventPath = 200

func newRequestEvent(r agent.Request, at time.Time) requestEvent {
	return requestEvent{
		Event: "request", Time: at.UTC().Format(time.RFC3339), Name: r.Tunnel, Method: shorten(r.Method, 16),
		Path: shorten(r.Path, maxEventPath), Status: r.Status, DurationMS: float64(r.Duration.Microseconds()) / 1000, Replay: r.Replay,
	}
}

// shorten cuts s to at most n bytes, at a character boundary, marking the cut.
func shorten(s string, n int) string {
	if len(s) <= n {
		return s
	}
	cut := n
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut] + "…"
}

func newErrorEvent(err error) errorEvent {
	code := "error"
	if errors.Is(err, errNotLoggedIn) {
		code = "not_logged_in"
	}
	return errorEvent{Event: "error", Code: code, Message: err.Error()}
}

// wantsJSON reports whether --json is among the arguments of a command, so
// that even an error found before the flags are parsed is printed as JSON.
func wantsJSON(args []string) bool {
	for _, a := range args {
		if a == "--" {
			return false
		}
		switch strings.TrimLeft(a, "-") {
		case "json", "json=true":
			return strings.HasPrefix(a, "-")
		}
	}
	return false
}
