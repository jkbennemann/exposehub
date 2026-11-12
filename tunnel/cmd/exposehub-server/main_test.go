package main

import (
	"bytes"
	"encoding/json"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func env(vars map[string]string) func(string) string {
	return func(k string) string { return vars[k] }
}

func TestHealthcheckWantsOKFromAGivenURL(t *testing.T) {
	ok := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	defer ok.Close()
	bad := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer bad.Close()

	if err := healthcheck(ok.URL, env(nil)); err != nil {
		t.Errorf("healthy server: %v", err)
	}
	if err := healthcheck(bad.URL, env(nil)); err == nil {
		t.Error("a 503 counted as healthy")
	}
}

func TestHealthcheckDefaultsToTheInternalListener(t *testing.T) {
	var path string
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { path = r.URL.Path }))
	defer ts.Close()
	_, port, _ := net.SplitHostPort(ts.Listener.Addr().String())

	if err := healthcheck("", env(map[string]string{"EXPOSEHUB_INTERNAL_LISTEN": ":" + port})); err != nil {
		t.Fatal(err)
	}
	if path != "/healthz" {
		t.Errorf("asked %q, want /healthz", path)
	}
}

func TestHealthcheckWithoutInternalListenerTakesAnyAnswer(t *testing.T) {
	ts := httptest.NewServer(http.NotFoundHandler())
	defer ts.Close()
	_, port, _ := net.SplitHostPort(ts.Listener.Addr().String())

	if err := healthcheck("", env(map[string]string{"EXPOSEHUB_LISTEN": ":" + port})); err != nil {
		t.Errorf("a 404 from the main listener: %v", err)
	}
}

func TestHealthcheckFailsWhenNothingListens(t *testing.T) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	_, port, _ := net.SplitHostPort(l.Addr().String())
	l.Close()
	if err := healthcheck("", env(map[string]string{"EXPOSEHUB_LISTEN": ":" + port})); err == nil {
		t.Error("healthy with nothing listening")
	}
}

func TestMaxStaleReadsADurationAndZeroMeansForever(t *testing.T) {
	for v, want := range map[string]time.Duration{"": 0, "90s": 90 * time.Second, "0": -1} {
		if got, err := maxStale(v); err != nil || got != want {
			t.Errorf("maxStale(%q) = %v, %v; want %v", v, got, err, want)
		}
	}
	for _, v := range []string{"soon", "-1m"} {
		if _, err := maxStale(v); err == nil {
			t.Errorf("maxStale(%q) accepted", v)
		}
	}
}

func TestLogFormatIsTextByDefaultAndJSONOnRequest(t *testing.T) {
	for _, format := range []string{"", "text"} {
		var out bytes.Buffer
		h, err := logHandler(format, &out, slog.LevelInfo)
		if err != nil {
			t.Fatal(err)
		}
		slog.New(h).Info("started", "listen", ":8080")
		if got := out.String(); !bytes.Contains(out.Bytes(), []byte("msg=started listen=:8080")) {
			t.Errorf("format %q wrote %q, want a key=value line", format, got)
		}
	}

	var out bytes.Buffer
	h, err := logHandler("json", &out, slog.LevelInfo)
	if err != nil {
		t.Fatal(err)
	}
	log := slog.New(h)
	log.Debug("below the level")
	log.Info("started", "listen", ":8080")
	var line struct{ Level, Msg, Listen string }
	if err := json.Unmarshal(out.Bytes(), &line); err != nil {
		t.Fatalf("%q is not one JSON object: %v", out.String(), err)
	}
	if line.Level != "INFO" || line.Msg != "started" || line.Listen != ":8080" {
		t.Errorf("got %+v", line)
	}

	if _, err := logHandler("xml", &out, slog.LevelInfo); err == nil {
		t.Error("an unknown format is accepted")
	}
}
