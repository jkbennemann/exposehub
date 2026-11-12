package inspector

import (
	"bytes"
	"io"
	"net/http/httptest"
	"strings"
	"testing"
)

func record(s *Store, method, url string, body string, status int, respBody string) {
	r := httptest.NewRequest(method, url, strings.NewReader(body))
	rec := s.Record("web", r, "")
	_, _ = io.Copy(io.Discard, rec.Body(r.Body))
	w := rec.Writer(httptest.NewRecorder())
	w.WriteHeader(status)
	_, _ = w.Write([]byte(respBody))
	rec.Finish(status)
}

func TestCaptureKeepsBodiesUpToTheLimit(t *testing.T) {
	s := NewStore()
	big := strings.Repeat("x", BodyLimit+100)
	record(s, "POST", "http://demo.test/upload?x=1", big, 201, "ok")

	sum := s.List()[0]
	if sum.Method != "POST" || sum.URL != "/upload?x=1" || sum.Status != 201 || !sum.Done {
		t.Fatalf("summary = %+v", sum)
	}
	e, _ := s.Get(sum.ID)
	if e.RequestBody.Size != int64(len(big)) || len(e.RequestBody.Data) != BodyLimit || !e.RequestBody.Truncated {
		t.Errorf("request body: size %d, kept %d, truncated %v", e.RequestBody.Size, len(e.RequestBody.Data), e.RequestBody.Truncated)
	}
	if string(e.ResponseBody.Data) != "ok" || e.ResponseBody.Truncated {
		t.Errorf("response body = %q", e.ResponseBody.Data)
	}
}

func TestTheStoreForgetsTheOldestFirst(t *testing.T) {
	s := NewStore()
	s.maxItems = 3
	for i := range 5 {
		record(s, "GET", "http://demo.test/"+string(rune('a'+i)), "", 200, "")
	}
	var urls []string
	for _, sum := range s.List() {
		urls = append(urls, sum.URL)
	}
	if strings.Join(urls, " ") != "/e /d /c" {
		t.Errorf("kept %v, want the newest three, newest first", urls)
	}

	s = NewStore()
	s.maxBytes = 3 * BodyLimit
	for range 6 {
		record(s, "POST", "http://demo.test/", strings.Repeat("y", BodyLimit), 200, "")
	}
	if n := len(s.List()); n >= 6 || n == 0 {
		t.Errorf("kept %d exchanges of half a MiB each under a 1.5 MiB budget", n)
	}
}

func TestSubscribersHearAboutStartAndEnd(t *testing.T) {
	s := NewStore()
	events, stop := s.Subscribe()
	defer stop()
	record(s, "GET", "http://demo.test/", "", 204, "")

	first, second := <-events, <-events
	if first.Done || !second.Done || first.ID != second.ID || second.Status != 204 {
		t.Errorf("events: %+v then %+v", first, second)
	}
}

func TestThePageAnswersOnlyOnLoopbackNames(t *testing.T) {
	h := Handler(NewStore())
	for host, want := range map[string]int{
		"127.0.0.1:4040":    200,
		"localhost:4040":    200,
		"[::1]:4040":        200,
		"evil.example:4040": 403, // DNS rebinding: a foreign name pointed at 127.0.0.1
		"127.0.0.1.nip.io":  403,
		"192.168.1.10:4040": 403,
	} {
		r := httptest.NewRequest("GET", "/api/requests", nil)
		r.Host = host
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != want {
			t.Errorf("Host %s: status %d, want %d", host, w.Code, want)
		}
	}
}

func TestOtherSitesCannotChangeAnything(t *testing.T) {
	s := NewStore()
	record(s, "GET", "http://demo.test/", "", 200, "")
	h := Handler(s)

	do := func(origin string, header bool) int {
		r := httptest.NewRequest("DELETE", "/api/requests", nil)
		r.Host = "127.0.0.1:4040"
		if origin != "" {
			r.Header.Set("Origin", origin)
		}
		if header {
			r.Header.Set(actionHeader, "1")
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w.Code
	}

	if code := do("", false); code != 403 {
		t.Errorf("without the action header: %d, want 403", code)
	}
	if code := do("https://evil.example", true); code != 403 {
		t.Errorf("from another origin: %d, want 403", code)
	}
	if len(s.List()) != 1 {
		t.Fatal("a refused request cleared the store")
	}
	if code := do("http://127.0.0.1:4040", true); code != 204 {
		t.Errorf("from the page itself: %d, want 204", code)
	}
	if len(s.List()) != 0 {
		t.Error("the page could not clear the store")
	}
}

func TestThePageIsServed(t *testing.T) {
	h := Handler(NewStore())
	for _, path := range []string{"/", "/app.js", "/app.css", "/fonts/barlow-latin-400-normal.woff2"} {
		r := httptest.NewRequest("GET", path, nil)
		r.Host = "127.0.0.1:4040"
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != 200 || w.Body.Len() == 0 {
			t.Errorf("%s: status %d, %d bytes", path, w.Code, w.Body.Len())
		}
		if csp := w.Header().Get("Content-Security-Policy"); !strings.Contains(csp, "script-src 'self'") {
			t.Errorf("%s: Content-Security-Policy = %q", path, csp)
		}
	}
	r := httptest.NewRequest("GET", "/", nil)
	r.Host = "127.0.0.1:4040"
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if !bytes.Contains(w.Body.Bytes(), []byte("exposehub")) {
		t.Error("the page does not look like the inspector")
	}
}
