package server

import (
	"bufio"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"io"
	"math"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/errorpage"
)

// A demo token belongs to the dashboard's demo account, which anyone can use
// without signing up. Its tunnels are public addresses under the install's
// tunnel domain that nobody answers for, so the server holds them to tighter
// rules than a team's:
//
//   - the address is always a random one; no name can be chosen,
//   - a token has one tunnel, and the install a fixed number of them at once,
//   - each answers a fixed number of requests a minute, and carries a fixed
//     amount of data a minute, whichever way and however few requests it is in,
//   - bodies are never kept in the request log, and
//   - a browser is told what it is about to open before the first page.
//
// The dashboard marks demo tokens in its snapshot; static tokens are never
// demo tokens.

const (
	// maxSessionsPerDemoToken leaves room for the session that serves, one
	// that is taking over from it after a network drop, and one more still
	// in its handshake.
	maxSessionsPerDemoToken = 3

	// demoCookie records that a browser has seen the notice of a demo
	// tunnel. demoContinuePath is where the notice's button leads; on a demo
	// tunnel it belongs to the server and never reaches the agent.
	demoCookie       = "__exposehub_demo"
	demoContinuePath = "/.exposehub/demo-continue"
	// demoNoticeFor is how long the notice stays away once seen. demoLinkFor
	// is how long the button on a notice works.
	demoNoticeFor = 30 * time.Minute
	demoLinkFor   = 10 * time.Minute
	// demoMaxReturn bounds the path a notice sends the visitor on to.
	demoMaxReturn = 2048
)

// allowance is what the tunnel of one demo token may use: requests, and
// bytes. It belongs to the token, not to the agent's session, and the server
// keeps it while sessions come and go: an agent that reconnects, or a second
// one that takes the address over, finds the allowance as the last one left
// it, not a fresh one.
type allowance struct {
	requests *bucket
	bytes    *bucket
}

// demoAllowance returns the allowance of a demo token, making it on first use.
func (s *Server) demoAllowance(tokenID string, now time.Time) *allowance {
	s.mu.Lock()
	defer s.mu.Unlock()
	a := s.allowances[tokenID]
	if a == nil {
		a = &allowance{
			requests: newBucket(s.cfg.DemoRequestsPerMinute, now),
			bytes:    newBucket(s.cfg.DemoMegabytesPerMinute<<20, now),
		}
		s.allowances[tokenID] = a
	}
	return a
}

// allowanceIdle is how long an allowance is kept after its token's last
// session and last use. By then both buckets are full again, so forgetting
// it gives nothing away.
const allowanceIdle = 3 * time.Minute

// sweepAllowances forgets the allowances of tokens that are gone.
func (s *Server) sweepAllowances(now time.Time) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for id, a := range s.allowances {
		if s.perToken[id] == 0 && a.requests.idle(now) > allowanceIdle && a.bytes.idle(now) > allowanceIdle {
			delete(s.allowances, id)
		}
	}
}

// bucket limits a demo tunnel to an amount a minute, of requests or of bytes:
// it holds up to a minute's worth, so a page with its assets loads at once,
// and fills up evenly.
type bucket struct {
	mu     sync.Mutex
	size   float64
	tokens float64
	last   time.Time
}

func newBucket(perMinute int, now time.Time) *bucket {
	return &bucket{size: float64(perMinute), tokens: float64(perMinute), last: now}
}

// take spends one request, or reports how long until there is one to spend.
func (b *bucket) take(now time.Time) (ok bool, wait time.Duration) {
	b.mu.Lock()
	defer b.mu.Unlock()
	perSecond := b.size / 60
	if elapsed := now.Sub(b.last).Seconds(); elapsed > 0 {
		b.tokens = math.Min(b.size, b.tokens+elapsed*perSecond)
		b.last = now
	}
	if b.tokens >= 1 {
		b.tokens--
		return true, 0
	}
	return false, time.Duration((1 - b.tokens) / perSecond * float64(time.Second))
}

// spend takes n at once, which may leave the bucket in debt, and reports how
// long it takes to fill up to zero again. Bytes are spent this way: they have
// passed by the time they are counted, and whoever sent them waits.
func (b *bucket) spend(n int, now time.Time) time.Duration {
	b.mu.Lock()
	defer b.mu.Unlock()
	perSecond := b.size / 60
	if elapsed := now.Sub(b.last).Seconds(); elapsed > 0 {
		b.tokens = math.Min(b.size, b.tokens+elapsed*perSecond)
		b.last = now
	}
	b.tokens -= float64(n)
	if b.tokens >= 0 {
		return 0
	}
	return time.Duration(-b.tokens / perSecond * float64(time.Second))
}

// idle reports how long ago the bucket was last used.
func (b *bucket) idle(now time.Time) time.Duration {
	b.mu.Lock()
	defer b.mu.Unlock()
	return now.Sub(b.last)
}

// meter makes everything a demo tunnel carries for one request wait for the
// tunnel's byte allowance: the request's body, the response, and both
// directions of a WebSocket. The request limit alone would not bound what a
// demo tunnel relays, since one request can stream for hours.
func (s *Server) meter(sess *session, w http.ResponseWriter, r *http.Request) http.ResponseWriter {
	p := &pacer{bytes: sess.limit.bytes, gone: r.Context().Done(), closed: sess.mux.CloseChan()}
	if r.Body != nil && r.Body != http.NoBody {
		r.Body = &meteredBody{ReadCloser: r.Body, pacer: p}
	}
	return &meteredWriter{ResponseWriter: w, pacer: p}
}

// pacer makes whoever moved bytes wait until the tunnel's allowance has
// caught up with them. It stops waiting when there is nobody left to hold
// back: the visitor has gone, or the tunnel has closed. Without that, a
// request would keep its place on the server, and its stream to the agent,
// long after the browser that made it went away.
type pacer struct {
	bytes  *bucket
	gone   <-chan struct{}
	closed <-chan struct{}
}

// wait accounts for n bytes that have passed. done, if not nil, ends the
// wait as well.
func (p *pacer) wait(n int, done <-chan struct{}) {
	if n <= 0 {
		return
	}
	d := p.bytes.spend(n, time.Now())
	if d <= 0 {
		return
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-t.C:
	case <-p.gone:
	case <-p.closed:
	case <-done:
	}
}

type meteredBody struct {
	io.ReadCloser
	pacer *pacer
}

func (b *meteredBody) Read(p []byte) (int, error) {
	n, err := b.ReadCloser.Read(p)
	b.pacer.wait(n, nil)
	return n, err
}

// meteredWriter paces a response. Unwrap lets the proxy flush through it;
// Hijack hands out the connection of an upgraded request, paced as well.
type meteredWriter struct {
	http.ResponseWriter
	pacer *pacer
}

func (w *meteredWriter) Write(p []byte) (int, error) {
	n, err := w.ResponseWriter.Write(p)
	w.pacer.wait(n, nil)
	return n, err
}

func (w *meteredWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

func (w *meteredWriter) Hijack() (net.Conn, *bufio.ReadWriter, error) {
	conn, rw, err := http.NewResponseController(w.ResponseWriter).Hijack()
	if err != nil {
		return nil, nil, err
	}
	// From here the request's context no longer says when the visitor
	// leaves; the proxy closes the connection when either side is done.
	return &meteredConn{Conn: conn, pacer: &pacer{bytes: w.pacer.bytes, closed: w.pacer.closed}, done: make(chan struct{})}, rw, nil
}

type meteredConn struct {
	net.Conn
	pacer *pacer
	done  chan struct{}
	once  sync.Once
}

func (c *meteredConn) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	c.pacer.wait(n, c.done)
	return n, err
}

func (c *meteredConn) Write(p []byte) (int, error) {
	n, err := c.Conn.Write(p)
	c.pacer.wait(n, c.done)
	return n, err
}

func (c *meteredConn) Close() error {
	c.once.Do(func() { close(c.done) })
	return c.Conn.Close()
}

// admit reports whether a visitor's request may go on to the agent. When it
// may not, the visitor has been answered.
func (s *Server) admit(sess *session, host string, w http.ResponseWriter, r *http.Request) bool {
	if !sess.who.Demo {
		// Networks first: a visitor from elsewhere is not even asked for a password.
		return s.reachable(sess, host, w, r) && sess.admits(host, w, r)
	}
	// The limit comes first, so that a flood costs as little as it can and
	// requests that are turned away count too. Then the networks: a visitor
	// who may not reach the tunnel learns nothing about it, not even that it
	// is a demo. The notice comes before the password, because typing a
	// password into a prompt is what the notice warns about.
	return s.demoWithinLimit(sess, w, r) &&
		s.reachable(sess, host, w, r) &&
		s.demoNoticed(host, w, r) &&
		sess.admits(host, w, r)
}

// demoWithinLimit spends one of the tunnel's requests, or answers 429.
func (s *Server) demoWithinLimit(sess *session, w http.ResponseWriter, r *http.Request) bool {
	ok, wait := sess.limit.requests.take(time.Now())
	if ok {
		return true
	}
	w.Header().Set("Retry-After", strconv.Itoa(max(1, int(math.Ceil(wait.Seconds())))))
	errorpage.Write(w, r, errorpage.DemoLimit(s.cfg.DemoRequestsPerMinute))
	return false
}

// demoNoticed lets a request through unless it is a browser opening a page
// of a demo tunnel for the first time; that browser gets the notice. Webhook
// senders, curl, scripts and a page's own requests pass: only a person
// reading a page can be warned, and can be phished.
func (s *Server) demoNoticed(host string, w http.ResponseWriter, r *http.Request) bool {
	now := time.Now()
	visitor := shortenIP(s.clientIP(r))
	seen := false
	for _, c := range r.Cookies() {
		if c.Name == demoCookie && s.demoTokenValid("seen", host, visitor, c.Value, now) {
			seen = true
		}
	}
	// The cookie is the server's; the service behind the tunnel never sees it.
	stripCookie(r, demoCookie)

	if r.URL.Path == demoContinuePath {
		to := localPath(r.URL.Query().Get("to"))
		if !seen {
			if !s.demoTokenValid("continue", host, visitor, r.URL.Query().Get("sig"), now) {
				// Not the button of a notice this visitor was shown: a link
				// handed out to skip the notice ends up at the notice.
				s.writeDemoNotice(w, r, host, visitor, to, now)
				return false
			}
			http.SetCookie(w, &http.Cookie{
				Name:     demoCookie,
				Value:    s.demoToken("seen", host, visitor, now.Add(demoNoticeFor)),
				Path:     "/",
				MaxAge:   int(demoNoticeFor.Seconds()),
				HttpOnly: true,
				SameSite: http.SameSiteLaxMode,
				Secure:   s.cfg.PublicScheme == "https",
			})
		}
		// Written by hand: http.Redirect would tidy the path, and the visitor
		// should arrive at the address they asked for.
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Location", to)
		w.WriteHeader(http.StatusSeeOther)
		return false
	}
	if seen || !isNavigation(r) {
		return true
	}
	s.writeDemoNotice(w, r, host, visitor, localPath(r.URL.RequestURI()), now)
	return false
}

// writeDemoNotice answers with the notice, whose button leads on to the path
// to. The proof travels as "sig", a name the request log blanks, so it does
// not show up in the log of the person who opened the tunnel.
func (s *Server) writeDemoNotice(w http.ResponseWriter, r *http.Request, host, visitor, to string, now time.Time) {
	link := demoContinuePath + "?to=" + url.QueryEscape(to) +
		"&sig=" + url.QueryEscape(s.demoToken("continue", host, visitor, now.Add(demoLinkFor)))
	w.Header().Set("X-Robots-Tag", "noindex")
	page := errorpage.DemoNotice(s.cfg.AgentHost, link)
	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		// What was sent has not reached the service. A browser shows the
		// notice whatever the status; a program must not take it for delivered.
		page.Status = http.StatusForbidden
	}
	errorpage.Write(w, r, page)
}

// isNavigation reports whether a browser is loading r as a page, in a tab or
// a frame. Browsers say so in Sec-Fetch-Mode, for a form sent with POST too.
// One that does not send the header, as every browser does over plain HTTP
// and older ones always, is taken to be navigating when it asks for HTML:
// with GET or HEAD, or with a POST that carries a form, which is what another
// site can make a browser send here. Webhook senders and scripts either do
// not ask for HTML or send something no form can.
func isNavigation(r *http.Request) bool {
	if mode := r.Header.Get("Sec-Fetch-Mode"); mode != "" {
		return mode == "navigate"
	}
	if r.Header.Get("Upgrade") != "" || !asksForHTML(r) {
		return false
	}
	switch r.Method {
	case http.MethodGet, http.MethodHead:
		return true
	case http.MethodPost:
		return isFormType(r.Header.Get("Content-Type"))
	}
	return false
}

func asksForHTML(r *http.Request) bool {
	for _, v := range r.Header.Values("Accept") {
		if strings.Contains(strings.ToLower(v), "text/html") {
			return true
		}
	}
	return false
}

// isFormType reports whether contentType is one of the three an HTML form
// can send.
func isFormType(contentType string) bool {
	kind, _, _ := strings.Cut(contentType, ";")
	switch strings.ToLower(strings.TrimSpace(kind)) {
	case "application/x-www-form-urlencoded", "multipart/form-data", "text/plain":
		return true
	}
	return false
}

// demoToken is proof, good until expires, that the server showed the notice
// of the tunnel at host to a visitor: the button's ("continue") or the
// cookie's ("seen"). It is bound to the tunnel and to the visitor's network,
// so the person who opened a demo tunnel cannot take the proof from their own
// browser and hand it to the people they send the address to.
func (s *Server) demoToken(purpose, host, visitor string, expires time.Time) string {
	exp := strconv.FormatInt(expires.Unix(), 10)
	return exp + "." + s.demoMAC(purpose, host, visitor, exp)
}

func (s *Server) demoMAC(purpose, host, visitor, exp string) string {
	m := hmac.New(sha256.New, s.demoKey[:])
	m.Write([]byte(purpose + "\n" + host + "\n" + visitor + "\n" + exp))
	return base64.RawURLEncoding.EncodeToString(m.Sum(nil)[:16])
}

func (s *Server) demoTokenValid(purpose, host, visitor, token string, now time.Time) bool {
	exp, mac, ok := strings.Cut(token, ".")
	if !ok {
		return false
	}
	expires, err := strconv.ParseInt(exp, 10, 64)
	if err != nil || now.Unix() > expires {
		return false
	}
	return hmac.Equal([]byte(mac), []byte(s.demoMAC(purpose, host, visitor, exp)))
}

// localPath returns to if it is a path on the same host, and "/" otherwise.
// The notice redirects to it, so it must not be able to name another site: no
// scheme, no host, no "//" or backslash that a browser reads as one, and no
// characters that would not survive in a Location header as they are.
func localPath(to string) string {
	if len(to) == 0 || len(to) > demoMaxReturn || to[0] != '/' || strings.HasPrefix(to, "//") {
		return "/"
	}
	for i := 0; i < len(to); i++ {
		if c := to[i]; c <= ' ' || c >= 0x7f || c == '\\' {
			return "/"
		}
	}
	u, err := url.Parse(to)
	if err != nil || u.Scheme != "" || u.Host != "" || u.Path == demoContinuePath {
		return "/"
	}
	return to
}

// stripCookie removes the cookie called name from a request.
func stripCookie(r *http.Request, name string) {
	lines := r.Header.Values("Cookie")
	if len(lines) == 0 {
		return
	}
	var kept []string
	found := false
	for _, line := range lines {
		for _, pair := range strings.Split(line, ";") {
			pair = strings.TrimSpace(pair)
			if key, _, _ := strings.Cut(pair, "="); strings.TrimSpace(key) == name {
				found = true
			} else if pair != "" {
				kept = append(kept, pair)
			}
		}
	}
	switch {
	case !found:
	case len(kept) == 0:
		r.Header.Del("Cookie")
	default:
		r.Header.Set("Cookie", strings.Join(kept, "; "))
	}
}

// demoResponse is a demo tunnel's answer on its way to the visitor. The
// notice cookie is the server's alone: a service behind a demo tunnel that
// sets it is not passed on, so it cannot mark the notice as seen for the
// people it is shown to. And browsers are told that the answer depends on how
// it was asked for.
func (s *Server) demoResponse(resp *http.Response) error {
	// A page fetched in the background, which gets no notice, must not be
	// what a browser later shows from its cache when the visitor opens the
	// same address as a page.
	resp.Header.Add("Vary", "Sec-Fetch-Mode")
	if cookies := resp.Header.Values("Set-Cookie"); len(cookies) > 0 {
		kept := make([]string, 0, len(cookies))
		for _, c := range cookies {
			if key, _, _ := strings.Cut(c, "="); strings.TrimSpace(key) != demoCookie {
				kept = append(kept, c)
			}
		}
		if len(kept) == 0 {
			resp.Header.Del("Set-Cookie")
		} else {
			resp.Header["Set-Cookie"] = kept
		}
	}
	return s.scopeCookies(resp)
}

// randomShaped reports whether label looks like an address the server gave
// out: the form randomLabel produces.
func randomShaped(label string) bool {
	if len(label) != 10 {
		return false
	}
	for i := 0; i < len(label); i++ {
		if c := label[i]; (c < 'a' || c > 'z') && (c < '2' || c > '7') {
			return false
		}
	}
	return true
}
