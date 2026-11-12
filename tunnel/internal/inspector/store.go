// Package inspector keeps the requests that came through the agent's tunnels
// and shows them on a page served on the developer's own machine.
package inspector

import (
	"crypto/rand"
	"encoding/hex"
	"io"
	"net/http"
	"strings"
	"sync"
	"time"
)

const (
	// BodyLimit is how much of each request and response body is kept.
	BodyLimit = 512 << 10
	// The store forgets the oldest exchanges beyond these bounds.
	defaultMaxItems = 1000
	defaultMaxBytes = 64 << 20
)

// Body is one captured request or response body.
type Body struct {
	Data      []byte `json:"data"` // base64 in JSON
	Size      int64  `json:"size"` // the full size, also when Data is cut short
	Truncated bool   `json:"truncated"`
}

// Exchange is one request through a tunnel and its response.
type Exchange struct {
	ID       string    `json:"id"`
	Tunnel   string    `json:"tunnel"`
	ReplayOf string    `json:"replay_of,omitempty"`
	Started  time.Time `json:"started"`
	// DurationMS is set when the exchange is done.
	DurationMS float64 `json:"duration_ms"`
	Done       bool    `json:"done"`

	Method   string `json:"method"`
	URL      string `json:"url"`
	Host     string `json:"host"`
	Proto    string `json:"proto"`
	Remote   string `json:"remote,omitempty"`
	Upgraded bool   `json:"upgraded,omitempty"`

	RequestHeader  http.Header `json:"request_header"`
	RequestBody    Body        `json:"request_body"`
	Status         int         `json:"status"`
	ResponseHeader http.Header `json:"response_header"`
	ResponseBody   Body        `json:"response_body"`
	// Error says why the local service gave no answer. The visitor only got
	// a generic page, so this is where the developer sees the cause.
	Error string `json:"error,omitempty"`
}

// Summary is what the list shows: an exchange without headers and bodies.
type Summary struct {
	ID          string    `json:"id"`
	Tunnel      string    `json:"tunnel"`
	ReplayOf    string    `json:"replay_of,omitempty"`
	Started     time.Time `json:"started"`
	DurationMS  float64   `json:"duration_ms"`
	Done        bool      `json:"done"`
	Method      string    `json:"method"`
	URL         string    `json:"url"`
	Status      int       `json:"status"`
	Upgraded    bool      `json:"upgraded,omitempty"`
	RequestSize int64     `json:"request_size"`
	Size        int64     `json:"size"`
	ContentType string    `json:"content_type,omitempty"`
}

func (e *Exchange) summary() Summary {
	return Summary{
		ID: e.ID, Tunnel: e.Tunnel, ReplayOf: e.ReplayOf, Started: e.Started, DurationMS: e.DurationMS,
		Done: e.Done, Method: e.Method, URL: e.URL, Status: e.Status, Upgraded: e.Upgraded,
		RequestSize: e.RequestBody.Size, Size: e.ResponseBody.Size,
		ContentType: e.ResponseHeader.Get("Content-Type"),
	}
}

func (e *Exchange) weight() int64 {
	return int64(len(e.RequestBody.Data)+len(e.ResponseBody.Data)) + 2048
}

// Tunnel describes an open tunnel, for the page's header.
type Tunnel struct {
	Name      string `json:"name"`
	URL       string `json:"url"`
	Upstream  string `json:"upstream"`
	Protected bool   `json:"protected,omitempty"`
}

// Replayer sends a captured request to the local service again.
type Replayer func(*Exchange) error

// Store holds the most recent exchanges in memory. It is safe for concurrent use.
type Store struct {
	maxItems int
	maxBytes int64

	mu       sync.Mutex
	items    []*Exchange // oldest first
	byID     map[string]*Exchange
	bytes    int64
	tunnels  []Tunnel
	replayer Replayer
	subs     map[chan Summary]struct{}
}

// NewStore returns an empty store with the default bounds.
func NewStore() *Store {
	return &Store{maxItems: defaultMaxItems, maxBytes: defaultMaxBytes, byID: map[string]*Exchange{}, subs: map[chan Summary]struct{}{}}
}

// SetTunnels records which tunnels are open.
func (s *Store) SetTunnels(t []Tunnel) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.tunnels = append([]Tunnel(nil), t...)
}

// Tunnels returns the open tunnels.
func (s *Store) Tunnels() []Tunnel {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]Tunnel(nil), s.tunnels...)
}

// SetReplayer installs the function that replays requests.
func (s *Store) SetReplayer(r Replayer) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.replayer = r
}

func (s *Store) put(e *Exchange) {
	s.mu.Lock()
	if old, ok := s.byID[e.ID]; ok {
		s.bytes -= old.weight()
		for i, it := range s.items {
			if it == old {
				s.items[i] = e
				break
			}
		}
	} else {
		s.items = append(s.items, e)
	}
	s.byID[e.ID] = e
	s.bytes += e.weight()
	for len(s.items) > s.maxItems || (s.bytes > s.maxBytes && len(s.items) > 1) {
		gone := s.items[0]
		s.items = s.items[1:]
		delete(s.byID, gone.ID)
		s.bytes -= gone.weight()
	}
	sum := e.summary()
	subs := make([]chan Summary, 0, len(s.subs))
	for ch := range s.subs {
		subs = append(subs, ch)
	}
	s.mu.Unlock()

	for _, ch := range subs {
		select {
		case ch <- sum:
		default: // a reader that falls behind misses updates rather than slowing the tunnel
		}
	}
}

// List returns all exchanges, newest first.
func (s *Store) List() []Summary {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := make([]Summary, 0, len(s.items))
	for i := len(s.items) - 1; i >= 0; i-- {
		out = append(out, s.items[i].summary())
	}
	return out
}

// Get returns one exchange. The result must not be modified.
func (s *Store) Get(id string) (*Exchange, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	e, ok := s.byID[id]
	return e, ok
}

// Clear forgets every exchange.
func (s *Store) Clear() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.items, s.byID, s.bytes = nil, map[string]*Exchange{}, 0
}

// Subscribe returns a channel that receives every new or updated exchange.
func (s *Store) Subscribe() (<-chan Summary, func()) {
	ch := make(chan Summary, 256)
	s.mu.Lock()
	s.subs[ch] = struct{}{}
	s.mu.Unlock()
	return ch, func() {
		s.mu.Lock()
		delete(s.subs, ch)
		s.mu.Unlock()
	}
}

// Replay sends the exchange's request to the local service again.
func (s *Store) Replay(e *Exchange) error {
	s.mu.Lock()
	r := s.replayer
	s.mu.Unlock()
	if r == nil {
		return errReplayUnavailable
	}
	return r(e)
}

// Record starts capturing one request. Wrap the request body and the response
// writer with the recorder, serve the request, then call Finish.
func (s *Store) Record(tunnel string, r *http.Request, replayOf string) *Recorder {
	remote, _, _ := strings.Cut(r.Header.Get("X-Forwarded-For"), ",")
	rec := &Recorder{store: s, ex: Exchange{
		ID: newID(), Tunnel: tunnel, ReplayOf: replayOf, Started: time.Now(),
		Method: r.Method, URL: r.URL.RequestURI(), Host: r.Host, Proto: r.Proto,
		Remote: strings.TrimSpace(remote), RequestHeader: r.Header.Clone(),
		Upgraded: r.Header.Get("Upgrade") != "",
	}}
	pending := rec.ex
	s.put(&pending)
	return rec
}

// Recorder captures one exchange as it passes through.
type Recorder struct {
	store  *Store
	ex     Exchange
	req    capture
	resp   capture
	mu     sync.Mutex
	status int
	header http.Header
	err    string
}

// SetError notes why the local service gave no answer; call it before Finish.
func (rc *Recorder) SetError(msg string) {
	rc.mu.Lock()
	defer rc.mu.Unlock()
	rc.err = msg
}

// Body wraps a request body so that what the proxy reads is also kept.
func (rc *Recorder) Body(body io.ReadCloser) io.ReadCloser {
	if body == nil || body == http.NoBody {
		return body
	}
	return &teeBody{ReadCloser: body, c: &rc.req}
}

// Writer wraps a response writer so that what is written is also kept.
func (rc *Recorder) Writer(w http.ResponseWriter) http.ResponseWriter {
	return &captureWriter{ResponseWriter: w, rc: rc}
}

// Finish completes the exchange with the status the client got.
func (rc *Recorder) Finish(status int) {
	rc.mu.Lock()
	if rc.status == 0 {
		rc.status = status
	}
	ex := rc.ex
	ex.Status, ex.ResponseHeader, ex.Error = rc.status, rc.header, rc.err
	rc.mu.Unlock()

	if ex.Status == 0 && ex.Upgraded {
		ex.Status = http.StatusSwitchingProtocols
	}
	ex.Done = true
	ex.DurationMS = float64(time.Since(ex.Started).Microseconds()) / 1000
	ex.RequestBody = rc.req.body()
	ex.ResponseBody = rc.resp.body()
	rc.store.put(&ex)
}

func (rc *Recorder) setHeader(status int, h http.Header) {
	rc.mu.Lock()
	defer rc.mu.Unlock()
	if rc.header == nil {
		rc.status, rc.header = status, h.Clone()
	}
}

// capture keeps the first BodyLimit bytes of a stream and counts the rest.
type capture struct {
	mu    sync.Mutex
	buf   []byte
	total int64
}

func (c *capture) write(p []byte) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.total += int64(len(p))
	if room := BodyLimit - len(c.buf); room > 0 {
		c.buf = append(c.buf, p[:min(room, len(p))]...)
	}
}

func (c *capture) body() Body {
	c.mu.Lock()
	defer c.mu.Unlock()
	return Body{Data: append([]byte(nil), c.buf...), Size: c.total, Truncated: c.total > int64(len(c.buf))}
}

type teeBody struct {
	io.ReadCloser
	c *capture
}

func (t *teeBody) Read(p []byte) (int, error) {
	n, err := t.ReadCloser.Read(p)
	if n > 0 {
		t.c.write(p[:n])
	}
	return n, err
}

// captureWriter keeps the response's status, headers and body. Unwrap lets
// the reverse proxy reach the real writer to flush and to hijack the
// connection for WebSockets, so neither goes through here.
type captureWriter struct {
	http.ResponseWriter
	rc *Recorder
}

func (w *captureWriter) WriteHeader(code int) {
	if code >= 200 || code == http.StatusSwitchingProtocols {
		w.rc.setHeader(code, w.Header())
	}
	w.ResponseWriter.WriteHeader(code)
}

func (w *captureWriter) Write(p []byte) (int, error) {
	w.rc.setHeader(http.StatusOK, w.Header())
	w.rc.resp.write(p)
	return w.ResponseWriter.Write(p)
}

func (w *captureWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

func newID() string {
	var b [8]byte
	_, _ = rand.Read(b[:])
	return hex.EncodeToString(b[:])
}
