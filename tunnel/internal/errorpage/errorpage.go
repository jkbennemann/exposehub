// Package errorpage answers a visitor whose request a tunnel could not serve.
//
// Browsers get a small HTML page that explains what happened in words and in
// a drawing of the tunnel's cable. Everything else (webhook senders, curl,
// API clients) gets the same one line of plain text as before, so nothing
// that parses these answers has to change.
//
// The page is served on tunnel addresses and on domains of the agents' users,
// so it is fully self-contained: no fonts, scripts or images are loaded from
// anywhere, and its Content-Security-Policy forbids them.
package errorpage

import (
	"bytes"
	_ "embed"
	"html/template"
	"net/http"
	"strconv"
	"strings"
)

// Cable is the state the drawing shows.
type Cable string

const (
	// Gap: the cable is cut and the far end is coming back (reconnecting).
	Gap Cable = "gap"
	// Loose: nothing is plugged in at the far end (no tunnel).
	Loose Cable = "loose"
	// Fault: the cable reaches the far end, but the far end does not answer.
	Fault Cable = "fault"
	// Locked: a lock sits on the cable (password protected).
	Locked Cable = "locked"
)

// Page describes one answer.
type Page struct {
	Status int
	Cable  Cable
	Title  string
	Text   string
	// Command is an optional line to type, shown under Hint.
	Hint    string
	Command string
	// Detail is an optional technical line, such as the local error.
	Detail string
	// Retry, if positive, makes the browser load the address again after
	// that many seconds. Only applied to GET requests.
	Retry int
	// Plain is the text non-browsers get, without the trailing newline.
	Plain string
}

//go:embed page.html.tmpl
var pageSource string

var page = template.Must(template.New("page").Parse(pageSource))

// csp allows the page's own inline styles and nothing else.
const csp = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"

// Write sends p to the visitor of r.
func Write(w http.ResponseWriter, r *http.Request, p Page) {
	h := w.Header()
	h.Set("Cache-Control", "no-store")
	h.Set("X-Content-Type-Options", "nosniff")
	if p.Retry > 0 {
		h.Set("Retry-After", strconv.Itoa(p.Retry))
	}

	if !wantsHTML(r) {
		h.Set("Content-Type", "text/plain; charset=utf-8")
		w.WriteHeader(p.Status)
		_, _ = w.Write([]byte(p.Plain + "\n"))
		return
	}

	retry := 0
	if r.Method == http.MethodGet {
		retry = p.Retry
	}
	var buf bytes.Buffer
	err := page.Execute(&buf, struct {
		Page
		Host     string
		Refresh  int
		StatusTx string
	}{p, hostOnly(r.Host), retry, http.StatusText(p.Status)})
	if err != nil { // only possible with a broken template, which the tests catch
		h.Set("Content-Type", "text/plain; charset=utf-8")
		w.WriteHeader(p.Status)
		_, _ = w.Write([]byte(p.Plain + "\n"))
		return
	}
	h.Set("Content-Type", "text/html; charset=utf-8")
	h.Set("Content-Security-Policy", csp)
	h.Set("Referrer-Policy", "no-referrer")
	w.WriteHeader(p.Status)
	_, _ = w.Write(buf.Bytes())
}

// wantsHTML reports whether the client is a browser asking for a page.
func wantsHTML(r *http.Request) bool {
	for _, v := range r.Header.Values("Accept") {
		if strings.Contains(v, "text/html") {
			return true
		}
	}
	return false
}

func hostOnly(host string) string {
	if i := strings.LastIndexByte(host, ':'); i > 0 && !strings.Contains(host[i:], "]") {
		host = host[:i]
	}
	return strings.ToLower(host)
}
