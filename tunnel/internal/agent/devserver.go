package agent

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Dev servers check where a request comes from, so that a page on some other
// site cannot talk to them. Through a tunnel the host and the origin are the
// public name, which they have never heard of. This file holds what the agent
// does about it. Vite and webpack refuse the page itself, by its Host: the
// tunnel is switched to their own name. Next.js serves the page but refuses
// its dev endpoints, hot reload among them, by the Origin: requests that come
// from the tunnel's own pages are passed on as coming from the dev server's
// own address. Where the developer asked for the public name to be kept, the
// agent changes nothing and says which line of configuration allows it.

// hostRefusals are the first bytes of the 403 a dev server answers a Host it
// does not accept with: Vite ("Blocked request. This host ("a.example.eu") is
// not allowed.") and webpack-dev-server ("Invalid Host header").
var hostRefusals = [][]byte{
	[]byte("Blocked request. This host ("),
	[]byte("Invalid Host header"),
}

// refusalPeek is how much of a 403 is read to recognise a refusal: the
// longest of hostRefusals.
var refusalPeek = func() int {
	n := 0
	for _, r := range hostRefusals {
		n = max(n, len(r))
	}
	return n
}()

// nextRefusal is all a Next.js dev server answers a request to one of its
// dev endpoints from an origin it does not allow (seen with 16.4.6): as the
// body of a 403, or, for the hot-reload socket, written to the connection in
// place of an HTTP response, which the transport reports as malformed.
const nextRefusal = "Unauthorized"

// hostFallback is the transport of a tunnel that keeps the public name as
// Host because nobody chose otherwise. When the local service refuses that
// name the way dev servers do, it switches the tunnel to the service's own
// name, calls switched once, and sends the request again if it had no body.
// A request with a body keeps its refusal, since the body is spent; the next
// one goes through. Any other 403 passes untouched.
type hostFallback struct {
	base     http.RoundTripper
	t        *tunnel
	switched func(publicHost string)
}

func (h *hostFallback) RoundTrip(req *http.Request) (*http.Response, error) {
	resp, err := h.base.RoundTrip(req)
	// An empty Host means the request already went out under the service's own name.
	if err != nil || resp.StatusCode != http.StatusForbidden || req.Host == "" {
		return resp, err
	}
	head := make([]byte, refusalPeek)
	n, _ := io.ReadFull(resp.Body, head)
	head = head[:n]
	rest := resp.Body
	resp.Body = struct {
		io.Reader
		io.Closer
	}{io.MultiReader(bytes.NewReader(head), rest), rest}
	if !refusesHost(head) {
		return resp, nil
	}
	if h.t.rewriteHost.CompareAndSwap(false, true) && h.switched != nil {
		h.switched(req.Host)
	}
	if req.Body != nil && req.Body != http.NoBody {
		return resp, nil
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(rest, 4<<10))
	rest.Close()
	again := req.Clone(req.Context())
	again.Host = ""
	return h.base.RoundTrip(again)
}

func refusesHost(head []byte) bool {
	for _, r := range hostRefusals {
		if bytes.HasPrefix(head, r) {
			return true
		}
	}
	return false
}

// hostSwitchNotice tells the developer that a tunnel changed what it sends as Host.
func hostSwitchNotice(upstream *url.URL, publicHost string) string {
	return fmt.Sprintf("%s turned away requests for %s, as Vite and webpack dev servers do with names they do not know. "+
		"It now gets its own name as Host, and the public one in X-Forwarded-Host. Choose the host header \"preserve\" to keep the public name.",
		upstream, hostOnly(publicHost))
}

// nextInternal reports whether Next.js counts the path among its dev
// endpoints, the ones it guards by origin. It is Next's own test, from
// block-cross-site-dev.js: the hot-reload socket (/_next/hmr with Turbopack,
// /_next/webpack-hmr with webpack), the error overlay's /__nextjs_* calls.
func nextInternal(path string) bool {
	if strings.Contains(path, "/_next/image") || strings.Contains(path, "/_next/static/media") ||
		strings.Contains(path, "/_next/static/immutable/media") {
		return false
	}
	return strings.Contains(path, "/_next") || strings.Contains(path, "/__nextjs")
}

// nextRefusedSocket reports whether err is Next.js turning down an upgrade.
func nextRefusedSocket(err error) bool {
	return err != nil && strings.Contains(err.Error(), "malformed HTTP response \""+nextRefusal+"\"")
}

// nextNotice says which line of configuration lets hot reload through.
func nextNotice(publicHost string) string {
	host := hostOnly(publicHost)
	return fmt.Sprintf("Next.js refused the hot-reload connection of %s. Pages load, but do not update when you edit. "+
		"To allow it, add allowedDevOrigins: ['%s'] to next.config.js and restart next dev.", host, host)
}

// nextOrigin is the transport of a tunnel to a loopback service whose
// developer did not ask for the public name to be kept. Once a Next.js dev
// server has refused one of its dev endpoints to the tunnel's own origin, it
// sends such requests on with the dev server's own origin, calls adopted once,
// and repeats the refused request if it had no body.
//
// Only an Origin that is the tunnel's own public address is replaced, and
// only on Next's dev endpoints. Pages of any other site keep their origin and
// stay refused, which is what the check is for; and the application's own
// routes see the real origin, which Server Actions compare with
// X-Forwarded-Host.
type nextOrigin struct {
	base    http.RoundTripper
	t       *tunnel
	adopted func(publicHost string)
}

func (n *nextOrigin) RoundTrip(req *http.Request) (*http.Response, error) {
	public := ownOrigin(req)
	if public == "" {
		return n.base.RoundTrip(req)
	}
	if n.t.localOrigin.Load() {
		return n.base.RoundTrip(n.asLocal(req))
	}
	resp, err := n.base.RoundTrip(req)
	if !nextRefusedSocket(err) {
		if err != nil || resp.StatusCode != http.StatusForbidden {
			return resp, err
		}
		head := make([]byte, len(nextRefusal)+1)
		k, _ := io.ReadFull(resp.Body, head)
		rest := resp.Body
		resp.Body = struct {
			io.Reader
			io.Closer
		}{io.MultiReader(bytes.NewReader(head[:k]), rest), rest}
		if string(head[:k]) != nextRefusal {
			return resp, nil
		}
	}
	if n.t.localOrigin.CompareAndSwap(false, true) && n.adopted != nil {
		n.adopted(public)
	}
	if req.Body != nil && req.Body != http.NoBody {
		return resp, err
	}
	if resp != nil {
		resp.Body.Close()
	}
	return n.base.RoundTrip(n.asLocal(req))
}

// asLocal returns req with the dev server's own address as its Origin.
// Next.js always allows localhost, whatever address it listens on.
func (n *nextOrigin) asLocal(req *http.Request) *http.Request {
	host := "localhost"
	if port := n.t.Upstream.Port(); port != "" {
		host = net.JoinHostPort(host, port)
	}
	local := req.Clone(req.Context())
	local.Header.Set("Origin", n.t.Upstream.Scheme+"://"+host)
	return local
}

// ownOrigin returns the public host of the tunnel if req goes to one of
// Next's dev endpoints and its Origin is that same host: a request from one
// of the tunnel's own pages. Otherwise it returns "".
func ownOrigin(req *http.Request) string {
	origin := req.Header.Get("Origin")
	if origin == "" || !nextInternal(req.URL.Path) {
		return ""
	}
	// The tunnel server sets X-Forwarded-Host from the visitor's connection;
	// Host holds the public name only while the tunnel preserves it.
	public := req.Header.Get("X-Forwarded-Host")
	if public == "" {
		public = req.Host
	}
	u, err := url.Parse(origin)
	if err != nil || public == "" || !strings.EqualFold(u.Host, public) {
		return ""
	}
	return public
}

// nextOriginNotice tells the developer that the tunnel's pages now reach the
// Next.js dev endpoints.
func nextOriginNotice(upstream *url.URL, publicHost string) string {
	return fmt.Sprintf("Next.js at %s allows hot reload only from its own address. Pages opened at %s now reach its dev endpoints as if from there; "+
		"other sites stay shut out. Choose the host header \"preserve\" to leave this to allowedDevOrigins in next.config.js.",
		upstream, hostOnly(publicHost))
}

// hostOnly drops the port of a Host header.
func hostOnly(hostport string) string {
	if host, _, err := net.SplitHostPort(hostport); err == nil {
		return host
	}
	return hostport
}

// Reachable reports whether something accepts connections where the upstream
// points. It tells a developer early that the local service is not running;
// it says nothing about whether that service answers HTTP.
func Reachable(ctx context.Context, upstream *url.URL) error {
	port := upstream.Port()
	if port == "" {
		port = "80"
		if upstream.Scheme == "https" {
			port = "443"
		}
	}
	conn, err := (&net.Dialer{Timeout: time.Second}).DialContext(ctx, "tcp", net.JoinHostPort(upstream.Hostname(), port))
	if err != nil {
		return err
	}
	return conn.Close()
}
