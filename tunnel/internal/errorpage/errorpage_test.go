package errorpage

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func allPages() map[string]Page {
	return map[string]Page{
		"reconnecting": Reconnecting(),
		"no tunnel":    NoTunnel("exposehub http <port> --subdomain demo"),
		"no answer":    TunnelDidNotAnswer(),
		"password":     PasswordRequired(),
		"local down":   LocalServiceDown(),
		"unknown":      UnknownTunnel(),
		"network":      NetworkDenied("203.0.113.9"),
	}
}

func serve(p Page, method, accept, host string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, "http://"+host+"/path", nil)
	if accept != "" {
		r.Header.Set("Accept", accept)
	}
	w := httptest.NewRecorder()
	Write(w, r, p)
	return w
}

func TestClientsThatAreNotBrowsersGetTheOneLine(t *testing.T) {
	for name, p := range allPages() {
		for _, accept := range []string{"", "*/*", "application/json"} {
			w := serve(p, http.MethodPost, accept, "demo.example.eu")
			if w.Code != p.Status {
				t.Errorf("%s: status %d, want %d", name, w.Code, p.Status)
			}
			if got := w.Body.String(); got != p.Plain+"\n" {
				t.Errorf("%s with Accept %q: body %q, want %q", name, accept, got, p.Plain+"\n")
			}
			if ct := w.Header().Get("Content-Type"); !strings.HasPrefix(ct, "text/plain") {
				t.Errorf("%s: Content-Type %q", name, ct)
			}
		}
	}
}

func TestBrowsersGetAPage(t *testing.T) {
	const accept = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
	for name, p := range allPages() {
		w := serve(p, http.MethodGet, accept, "demo.example.eu:8443")
		body := w.Body.String()
		if w.Code != p.Status {
			t.Errorf("%s: status %d, want %d", name, w.Code, p.Status)
		}
		if ct := w.Header().Get("Content-Type"); ct != "text/html; charset=utf-8" {
			t.Errorf("%s: Content-Type %q", name, ct)
		}
		if csp := w.Header().Get("Content-Security-Policy"); !strings.Contains(csp, "default-src 'none'") {
			t.Errorf("%s: Content-Security-Policy %q", name, csp)
		}
		for _, want := range []string{"<title>" + p.Title + "</title>", "<h1>" + p.Title + "</h1>", `<p class="host">demo.example.eu</p>`, `class="cable"`} {
			if !strings.Contains(body, want) {
				t.Errorf("%s: page lacks %q", name, want)
			}
		}
		if strings.Contains(body, "<script") || strings.Contains(body, "src=") || strings.Contains(body, "href=") {
			t.Errorf("%s: page loads or links something", name)
		}
	}
}

func TestEveryCableStateHasADrawing(t *testing.T) {
	marks := map[Cable]string{Gap: `class="returning"`, Loose: `class="socket"`, Fault: `class="broken"`, Locked: `class="lock"`}
	for cable, mark := range marks {
		body := serve(Page{Status: 502, Cable: cable, Title: "t", Plain: "p"}, http.MethodGet, "text/html", "a.example").Body.String()
		if !strings.Contains(body, mark) {
			t.Errorf("cable %q: drawing lacks %s", cable, mark)
		}
	}
}

func TestTheHostIsEscaped(t *testing.T) {
	r := httptest.NewRequest(http.MethodGet, "http://x.example/", nil)
	r.Host = `<script>alert(1)</script>.example`
	r.Header.Set("Accept", "text/html")
	w := httptest.NewRecorder()
	Write(w, r, NoTunnel("exposehub http <port> --hostname <b>x</b>"))
	body := w.Body.String()
	if strings.Contains(body, "<script>") || strings.Contains(body, "<b>x</b>") {
		t.Fatalf("host or command not escaped:\n%s", body)
	}
}

func TestOnlyGetRequestsRetryByThemselves(t *testing.T) {
	p := Reconnecting()
	get := serve(p, http.MethodGet, "text/html", "a.example")
	if !strings.Contains(get.Body.String(), `http-equiv="refresh" content="5"`) {
		t.Error("GET page does not refresh")
	}
	if get.Header().Get("Retry-After") != "5" {
		t.Errorf("Retry-After %q", get.Header().Get("Retry-After"))
	}
	post := serve(p, http.MethodPost, "text/html", "a.example")
	if strings.Contains(post.Body.String(), `http-equiv="refresh"`) {
		t.Error("POST page refreshes, which would turn the visitor's POST into a GET")
	}
	if strings.Contains(serve(PasswordRequired(), http.MethodGet, "text/html", "a.example").Body.String(), `http-equiv="refresh"`) {
		t.Error("password page refreshes")
	}
}
