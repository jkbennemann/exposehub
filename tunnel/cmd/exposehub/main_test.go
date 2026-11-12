package main

import (
	"bytes"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/jkbennemann/exposehub/tunnel/internal/cliconfig"
	"github.com/jkbennemann/exposehub/tunnel/internal/selfupdate"
)

// setup gives each test its own config file and no server from the environment.
func setup(t *testing.T, builtIn string) {
	t.Helper()
	t.Setenv("EXPOSEHUB_CONFIG", filepath.Join(t.TempDir(), "config.json"))
	t.Setenv("EXPOSEHUB_SERVER", "")
	old := defaultServer
	defaultServer = builtIn
	t.Cleanup(func() { defaultServer = old })
}

// capture runs fn with stdin set to input and returns what it printed.
func capture(t *testing.T, input string, fn func() error) (string, error) {
	t.Helper()
	in, _ := os.CreateTemp(t.TempDir(), "stdin")
	_, _ = in.WriteString(input)
	_, _ = in.Seek(0, io.SeekStart)
	r, w, _ := os.Pipe()
	oldIn, oldOut, oldErr := os.Stdin, os.Stdout, os.Stderr
	os.Stdin, os.Stdout, os.Stderr = in, w, w
	err := fn()
	os.Stdin, os.Stdout, os.Stderr = oldIn, oldOut, oldErr
	w.Close()
	var out bytes.Buffer
	_, _ = io.Copy(&out, r)
	return out.String(), err
}

func TestLoginUsesTheBuiltInServer(t *testing.T) {
	setup(t, "https://connect.built-in.example")

	out, err := capture(t, "eh_token\n", func() error { return runLogin(nil) })
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "Logging in to https://connect.built-in.example") {
		t.Errorf("login did not say where the token goes: %q", out)
	}
	saved, _ := cliconfig.Load()
	if saved.Server != "https://connect.built-in.example" || saved.Token != "eh_token" {
		t.Errorf("saved %+v", saved)
	}
}

func TestLoginWithoutAnyServerAsksForOne(t *testing.T) {
	setup(t, "")
	if _, err := capture(t, "eh_token\n", func() error { return runLogin(nil) }); err == nil || !strings.Contains(err.Error(), "which tunnel server") {
		t.Errorf("login returned %v, want a request for a server", err)
	}
	if saved, _ := cliconfig.Load(); saved != (cliconfig.Config{}) {
		t.Errorf("something was saved: %+v", saved)
	}
}

func TestASavedServerWinsOverTheBuiltInOne(t *testing.T) {
	setup(t, "https://connect.built-in.example")
	if _, err := capture(t, "", func() error { return runServer([]string{"connect.self-hosted.example"}) }); err != nil {
		t.Fatal(err)
	}
	out, err := capture(t, "eh_token\n", func() error { return runLogin(nil) })
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out, "Logging in to https://connect.self-hosted.example") {
		t.Errorf("login went to the wrong server: %q", out)
	}
}

func TestChangingTheServerDropsTheTokenForTheOldOne(t *testing.T) {
	setup(t, "")
	if _, err := cliconfig.Save(cliconfig.Config{Server: "https://a.example", Token: "eh_for_a"}); err != nil {
		t.Fatal(err)
	}

	// The same server again keeps the token.
	if _, err := capture(t, "", func() error { return runServer([]string{"a.example/"}) }); err != nil {
		t.Fatal(err)
	}
	if saved, _ := cliconfig.Load(); saved.Token != "eh_for_a" {
		t.Fatalf("token lost when saving the same server: %+v", saved)
	}

	out, err := capture(t, "", func() error { return runServer([]string{"https://b.example"}) })
	if err != nil {
		t.Fatal(err)
	}
	if saved, _ := cliconfig.Load(); saved != (cliconfig.Config{Server: "https://b.example"}) {
		t.Errorf("saved %+v, want only the new server", saved)
	}
	if !strings.Contains(out, "Log in again") {
		t.Errorf("no hint to log in again: %q", out)
	}
}

func TestServerSavedPrintsOnlyTheSavedServer(t *testing.T) {
	setup(t, "https://connect.built-in.example")
	if out, _ := capture(t, "", func() error { return runServer([]string{"--saved"}) }); out != "" {
		t.Errorf("--saved printed %q with nothing saved; the installer relies on empty output", out)
	}
	if out, _ := capture(t, "", func() error { return runServer(nil) }); !strings.Contains(out, "(built in)") {
		t.Errorf("server printed %q, want the built-in default named as such", out)
	}
	_, _ = cliconfig.Save(cliconfig.Config{Server: "https://saved.example"})
	if out, _ := capture(t, "", func() error { return runServer([]string{"--saved"}) }); out != "https://saved.example\n" {
		t.Errorf("--saved printed %q", out)
	}
}

func TestLogoutKeepsTheServer(t *testing.T) {
	setup(t, "")
	_, _ = cliconfig.Save(cliconfig.Config{Server: "https://a.example", Token: "eh_for_a"})
	if _, err := capture(t, "", runLogout); err != nil {
		t.Fatal(err)
	}
	if saved, _ := cliconfig.Load(); saved != (cliconfig.Config{Server: "https://a.example"}) {
		t.Errorf("after logout: %+v, want the server without the token", saved)
	}
}

// releaseServer offers this very agent version, so an update has nothing to
// replace and the test binary stays in place.
func releaseServer(t *testing.T) string {
	t.Helper()
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/agent/latest.json" {
			http.NotFound(w, r)
			return
		}
		fmt.Fprintf(w, `{"version":%q,"files":{%q:{"name":"exposehub-x","sha256":"00"}}}`, version, selfupdate.Platform())
	}))
	t.Cleanup(ts.Close)
	return ts.URL
}

func TestUpdateSavesTheBuiltInServer(t *testing.T) {
	base := releaseServer(t)
	setup(t, base)

	if _, err := capture(t, "", func() error { return runUpdate(nil) }); err != nil {
		t.Fatal(err)
	}
	if saved, _ := cliconfig.Load(); saved.Server != base {
		t.Errorf("saved server %q, want %q", saved.Server, base)
	}
}

func TestUpdateKeepsASavedServer(t *testing.T) {
	setup(t, "")
	if _, err := cliconfig.Save(cliconfig.Config{Server: "https://connect.mine.example", Token: "eh_t"}); err != nil {
		t.Fatal(err)
	}
	base := releaseServer(t)

	if _, err := capture(t, "", func() error { return runUpdate([]string{"--server", base}) }); err != nil {
		t.Fatal(err)
	}
	if saved, _ := cliconfig.Load(); saved.Server != "https://connect.mine.example" || saved.Token != "eh_t" {
		t.Errorf("saved %+v, want the earlier server and token untouched", saved)
	}
}
