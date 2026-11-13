package server

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"
)

// What a tunnel's requests are logged with in the dashboard.
const (
	LogOff      = "off"
	LogRequests = "requests" // method, path, status, time, sizes, shortened visitor address
	LogBodies   = "bodies"   // also headers and the first logBodyLimit bytes of each body
)

const (
	logBodyLimit  = 64 << 10
	logBatchSize  = 500
	logMaxPending = 20000
	// Batches are cut by size too, well below the 32 MiB the dashboard
	// accepts, and the queue is bounded by size, so large bodies-mode events
	// neither form a batch the dashboard refuses nor fill the server's memory.
	logMaxBatchBytes   = 8 << 20
	logMaxPendingBytes = 128 << 20
	// logMaxPath is as much of a path as the dashboard keeps.
	logMaxPath     = 2048
	logFlushPeriod = time.Second
	logIngestPath  = "/internal/v1/logs"
)

// errLogsRefused means the dashboard turned down this batch for what is in
// it. Sending the same batch again cannot succeed.
var errLogsRefused = errors.New("the dashboard refused the batch")

// LogEvent is one request as the dashboard stores it.
type LogEvent struct {
	TokenID       string      `json:"token_id"`
	Host          string      `json:"host"`
	Method        string      `json:"method"`
	Path          string      `json:"path"`
	Status        int         `json:"status"`
	DurationMS    float64     `json:"duration_ms"`
	RequestBytes  int64       `json:"request_bytes"`
	ResponseBytes int64       `json:"response_bytes"`
	Remote        string      `json:"remote,omitempty"`
	Started       time.Time   `json:"started"`
	Details       *LogDetails `json:"details,omitempty"`
}

// LogDetails is what a tunnel started with --log-bodies adds.
type LogDetails struct {
	RequestHeader     http.Header `json:"request_header"`
	ResponseHeader    http.Header `json:"response_header"`
	RequestBody       []byte      `json:"request_body"`
	ResponseBody      []byte      `json:"response_body"`
	RequestTruncated  bool        `json:"request_truncated"`
	ResponseTruncated bool        `json:"response_truncated"`
}

// ---------------------------------------------------------------- recording

// logRecord follows one request through ServeHTTP.
type logRecord struct {
	ev      LogEvent
	start   time.Time
	bodies  bool
	upgrade bool
	reqBody logCapture
	writer  *logWriter
}

// startLog begins recording a request to a tunnel, or returns nil when the
// tunnel is not logged: logging switched off for it, or no dashboard to send
// to, or an agent that authenticated with a static token, which has no owner
// in the dashboard.
func (s *Server) startLog(sess *session, host string, r *http.Request) *logRecord {
	if s.shipper == nil {
		return nil
	}
	if _, ok := sess.who.issuer.(*ControlPlane); !ok {
		return nil
	}
	mode := sess.logMode[host]
	if mode != LogRequests && mode != LogBodies {
		return nil
	}
	path := maskQuery(r.URL)
	if sess.who.Demo && r.URL.Path == demoContinuePath {
		// "to" carries the address the visitor first asked for, query and
		// all, where the masking of secret-looking parameters does not look.
		path = demoContinuePath + "?to=[redacted]&sig=[redacted]"
	}
	rec := &logRecord{
		start:   time.Now(),
		bodies:  mode == LogBodies,
		upgrade: r.Header.Get("Upgrade") != "",
		ev: LogEvent{
			TokenID: sess.who.TokenID,
			Host:    host,
			Method:  r.Method,
			Path:    truncateUTF8(path, logMaxPath),
			Remote:  shortenIP(s.clientIP(r)),
			Started: time.Now().UTC(),
		},
	}
	if rec.bodies {
		rec.ev.Details = &LogDetails{RequestHeader: redactHeaders(r.Header)}
	}
	return rec
}

// wrap installs the recording on the request body and the response writer.
func (rec *logRecord) wrap(w http.ResponseWriter, r *http.Request) http.ResponseWriter {
	if r.Body != nil && r.Body != http.NoBody {
		r.Body = &logBody{ReadCloser: r.Body, c: &rec.reqBody, keep: rec.bodies}
	}
	rec.writer = &logWriter{ResponseWriter: w, keep: rec.bodies}
	return rec.writer
}

func (rec *logRecord) finish(s *Server) {
	ev := rec.ev
	ev.DurationMS = float64(time.Since(rec.start).Microseconds()) / 1000
	ev.Status = rec.writer.status
	if ev.Status == 0 && rec.upgrade {
		ev.Status = http.StatusSwitchingProtocols
	}
	ev.RequestBytes = rec.reqBody.total()
	ev.ResponseBytes = rec.writer.body.total()
	if ev.Details != nil {
		ev.Details.ResponseHeader = redactHeaders(rec.writer.header)
		ev.Details.RequestBody, ev.Details.RequestTruncated = rec.reqBody.kept()
		ev.Details.ResponseBody, ev.Details.ResponseTruncated = rec.writer.body.kept()
	}
	s.shipper.Enqueue(ev)
}

// clientIP is the visitor's address, as visitorAddr finds it.
func (s *Server) clientIP(r *http.Request) string {
	if a := s.visitorAddr(r); a.IsValid() {
		return a.String()
	}
	return r.RemoteAddr
}

// shortenIP keeps the network, not the machine: an IPv4 address to its /24,
// an IPv6 address to its /48.
func shortenIP(addr string) string {
	ip := net.ParseIP(strings.TrimSpace(addr))
	if ip == nil {
		return ""
	}
	if v4 := ip.To4(); v4 != nil {
		return v4.Mask(net.CIDRMask(24, 32)).String()
	}
	return ip.Mask(net.CIDRMask(48, 128)).String()
}

// Headers whose values are secrets in almost every application.
var secretHeader = regexp.MustCompile(`(?i)^(authorization|proxy-authorization|cookie|set-cookie)$|token|secret|password|api-?key|session`)

func redactHeaders(h http.Header) http.Header {
	out := make(http.Header, len(h))
	for name, values := range h {
		if secretHeader.MatchString(name) {
			out[name] = []string{"[redacted]"}
			continue
		}
		out[name] = append([]string(nil), values...)
	}
	return out
}

// Query parameters whose values are often secrets: access tokens, API keys,
// signed-URL signatures, OAuth codes.
var secretParam = regexp.MustCompile(`(?i)token|key|secret|pass|sig|auth|code|session|credential`)

// maskQuery returns the path and query with secret-looking values replaced,
// keeping the order and encoding of everything else.
func maskQuery(u interface{ RequestURI() string }) string {
	uri := u.RequestURI()
	path, query, found := strings.Cut(uri, "?")
	if !found || query == "" {
		return uri
	}
	parts := strings.Split(query, "&")
	for i, part := range parts {
		name, _, hasValue := strings.Cut(part, "=")
		if hasValue && secretParam.MatchString(name) {
			parts[i] = name + "=[redacted]"
		}
	}
	return path + "?" + strings.Join(parts, "&")
}

// logCapture counts a stream's bytes and keeps the first logBodyLimit of them.
type logCapture struct {
	mu  sync.Mutex
	buf []byte
	n   int64
}

func (c *logCapture) write(p []byte, keep bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.n += int64(len(p))
	if keep {
		if room := logBodyLimit - len(c.buf); room > 0 {
			c.buf = append(c.buf, p[:min(room, len(p))]...)
		}
	}
}

func (c *logCapture) total() int64 {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.n
}

func (c *logCapture) kept() ([]byte, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	return append([]byte(nil), c.buf...), c.n > int64(len(c.buf))
}

type logBody struct {
	io.ReadCloser
	c    *logCapture
	keep bool
}

func (b *logBody) Read(p []byte) (int, error) {
	n, err := b.ReadCloser.Read(p)
	if n > 0 {
		b.c.write(p[:n], b.keep)
	}
	return n, err
}

// logWriter notes the status, headers and size of a response. Unwrap lets
// the proxy flush and hijack (WebSockets) the real writer.
type logWriter struct {
	http.ResponseWriter
	keep   bool
	status int
	header http.Header
	body   logCapture
}

func (w *logWriter) WriteHeader(code int) {
	if w.status == 0 && code >= 200 {
		w.status, w.header = code, w.Header().Clone()
	}
	w.ResponseWriter.WriteHeader(code)
}

func (w *logWriter) Write(p []byte) (int, error) {
	if w.status == 0 {
		w.status, w.header = http.StatusOK, w.Header().Clone()
	}
	w.body.write(p, w.keep)
	return w.ResponseWriter.Write(p)
}

func (w *logWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

// ------------------------------------------------------------------ sending

// LogShipper sends log events to the dashboard in batches. Requests to
// tunnels never wait for it: when the dashboard cannot be reached, events
// wait in memory, and beyond logMaxPending the oldest are dropped.
type LogShipper struct {
	url    string
	secret string
	client *http.Client
	log    *slog.Logger

	mu           sync.Mutex
	pending      []queuedEvent
	pendingBytes int
	dropped      int
	wake         chan struct{}
}

// queuedEvent is an event waiting to be sent, with its approximate size.
type queuedEvent struct {
	ev   LogEvent
	size int
}

// approxSize is roughly how many bytes ev takes as JSON. It only needs to be
// close: the dashboard's limit is far above the batches cut with it.
func (ev *LogEvent) approxSize() int {
	n := 256 + len(ev.TokenID) + len(ev.Host) + len(ev.Method) + len(ev.Path) + len(ev.Remote)
	if d := ev.Details; d != nil {
		n += headerSize(d.RequestHeader) + headerSize(d.ResponseHeader)
		n += (len(d.RequestBody) + len(d.ResponseBody)) * 4 / 3 // base64
	}
	return n
}

func headerSize(h http.Header) int {
	n := 0
	for name, values := range h {
		n += len(name) + 6
		for _, v := range values {
			n += len(v) + 3
		}
	}
	return n
}

// NewLogShipper sends to the control plane at baseURL.
func NewLogShipper(baseURL, secret string, log *slog.Logger) *LogShipper {
	return &LogShipper{
		url: strings.TrimRight(baseURL, "/") + logIngestPath, secret: secret, log: log,
		client: &http.Client{Timeout: 15 * time.Second},
		wake:   make(chan struct{}, 1),
	}
}

// SetLogShipper makes the server log requests to tunnels whose agent asked
// for it, through sh.
func (s *Server) SetLogShipper(sh *LogShipper) { s.shipper = sh }

// Enqueue adds an event. It never blocks.
func (sh *LogShipper) Enqueue(ev LogEvent) {
	q := queuedEvent{ev: ev, size: ev.approxSize()}
	sh.mu.Lock()
	sh.pending = append(sh.pending, q)
	sh.pendingBytes += q.size
	sh.trimLocked()
	full := len(sh.pending) >= logBatchSize || sh.pendingBytes >= logMaxBatchBytes
	sh.mu.Unlock()
	if full {
		select {
		case sh.wake <- struct{}{}:
		default:
		}
	}
}

// Run sends batches until ctx is cancelled, then makes one last attempt.
func (sh *LogShipper) Run(ctx context.Context) {
	tick := time.NewTicker(logFlushPeriod)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			final, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			sh.flush(final)
			cancel()
			return
		case <-tick.C:
		case <-sh.wake:
		}
		sh.flush(ctx)
	}
}

// trimLocked drops the oldest events beyond the queue's limits.
func (sh *LogShipper) trimLocked() {
	n := 0
	for n < len(sh.pending) && (len(sh.pending)-n > logMaxPending || sh.pendingBytes > logMaxPendingBytes) {
		sh.pendingBytes -= sh.pending[n].size
		n++
	}
	if n > 0 {
		sh.pending = sh.pending[n:]
		sh.dropped += n
	}
}

// flush sends everything pending, one batch at a time. When the dashboard
// cannot be reached it stops, and what was not sent goes back to the front to
// be retried later. A batch the dashboard refuses for its content is never
// retried as it is: it is split until the events it refuses on their own are
// found, and those are dropped.
func (sh *LogShipper) flush(ctx context.Context) {
	for {
		sh.mu.Lock()
		n, size := 0, 0
		for n < len(sh.pending) && n < logBatchSize {
			if n > 0 && size+sh.pending[n].size > logMaxBatchBytes {
				break
			}
			size += sh.pending[n].size
			n++
		}
		batch := append([]queuedEvent(nil), sh.pending[:n]...)
		sh.pending = sh.pending[n:]
		sh.pendingBytes -= size
		dropped := sh.dropped
		sh.dropped = 0
		sh.mu.Unlock()
		if dropped > 0 {
			sh.log.Warn("request log events dropped while the dashboard was unreachable", "count", dropped)
		}
		if n == 0 {
			return
		}
		unsent, refused, err := sh.send(ctx, batch)
		if refused > 0 {
			sh.log.Warn("the dashboard refused request log events; dropped them", "count", refused)
		}
		if err != nil {
			sh.mu.Lock()
			sh.pending = append(unsent, sh.pending...)
			for _, q := range unsent {
				sh.pendingBytes += q.size
			}
			sh.trimLocked()
			sh.mu.Unlock()
			sh.log.Warn("could not send request logs to the dashboard; will retry", "events", len(unsent), "err", err)
			return
		}
	}
}

// send posts batch. While the dashboard refuses it for its content, it is
// halved and each half sent on its own; a single event refused is dropped and
// counted in refused. Any other failure stops sending: unsent is what is left.
func (sh *LogShipper) send(ctx context.Context, batch []queuedEvent) (unsent []queuedEvent, refused int, err error) {
	err = sh.post(ctx, batch)
	switch {
	case err == nil:
		return nil, 0, nil
	case !errors.Is(err, errLogsRefused):
		return batch, 0, err
	case len(batch) == 1:
		return nil, 1, nil
	}
	mid := len(batch) / 2
	unsent, refused, err = sh.send(ctx, batch[:mid])
	if err != nil {
		return append(append([]queuedEvent(nil), unsent...), batch[mid:]...), refused, err
	}
	unsent, more, err := sh.send(ctx, batch[mid:])
	return unsent, refused + more, err
}

func (sh *LogShipper) post(ctx context.Context, batch []queuedEvent) error {
	events := make([]LogEvent, len(batch))
	for i, q := range batch {
		events[i] = q.ev
	}
	var body bytes.Buffer
	zw := gzip.NewWriter(&body)
	if err := json.NewEncoder(zw).Encode(map[string]any{"events": events}); err != nil {
		return err
	}
	if err := zw.Close(); err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, sh.url, &body)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+sh.secret)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Content-Encoding", "gzip")
	req.Header.Set("Accept", "application/json")
	resp, err := sh.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	switch {
	case resp.StatusCode/100 == 2:
		return nil
	case resp.StatusCode/100 == 4 && !retryableLogStatus(resp.StatusCode):
		return fmt.Errorf("%w: %s", errLogsRefused, resp.Status)
	}
	return fmt.Errorf("the dashboard answered %s", resp.Status)
}

// retryableLogStatus reports whether a 4xx answer says nothing about the
// batch, so the same batch may succeed later: a timeout, rate limiting, or a
// node secret or route the dashboard does not accept, which would refuse
// every batch, and splitting would only multiply the requests.
func retryableLogStatus(code int) bool {
	switch code {
	case http.StatusRequestTimeout, http.StatusTooManyRequests,
		http.StatusUnauthorized, http.StatusForbidden, http.StatusNotFound:
		return true
	}
	return false
}
