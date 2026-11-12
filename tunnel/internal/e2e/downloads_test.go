package e2e

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// releaseDir writes a fake agent release: two builds and their latest.json.
func releaseDir(t *testing.T, version string) string {
	t.Helper()
	dir := t.TempDir()
	files := map[string]map[string]string{}
	for _, platform := range []string{"linux-amd64", "darwin-arm64"} {
		name := "exposehub-" + platform
		body := []byte("agent build for " + platform)
		if err := os.WriteFile(filepath.Join(dir, name), body, 0o644); err != nil {
			t.Fatal(err)
		}
		sum := sha256.Sum256(body)
		files[platform] = map[string]string{"name": name, "sha256": hex.EncodeToString(sum[:])}
	}
	// A file that is in the directory but not in the release must not be served.
	if err := os.WriteFile(filepath.Join(dir, "secret.txt"), []byte("not for download"), 0o644); err != nil {
		t.Fatal(err)
	}
	manifest, _ := json.Marshal(map[string]any{"version": version, "files": files})
	if err := os.WriteFile(filepath.Join(dir, "latest.json"), manifest, 0o644); err != nil {
		t.Fatal(err)
	}
	return dir
}

func fetch(t *testing.T, h *harness, host, path string) (int, string) {
	t.Helper()
	resp, err := h.visitor.Get("http://" + host + path)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(body)
}

func TestTheAgentHostServesTheAgent(t *testing.T) {
	dir := releaseDir(t, "1.2.3")
	h := newHarness(t, func(cfg *server.Config) { cfg.AgentDownloads = dir })

	status, script := fetch(t, h, agentHost, "/install.sh")
	if status != 200 {
		t.Fatalf("/install.sh: status %d", status)
	}
	for _, want := range []string{"server='http://" + agentHost + "'", "version='1.2.3'", "linux-amd64) file='exposehub-linux-amd64'", "darwin-arm64) file='exposehub-darwin-arm64'", `"$dir/exposehub" server "$server"`} {
		if !strings.Contains(script, want) {
			t.Errorf("install.sh lacks %q", want)
		}
	}
	syntax := exec.Command("sh", "-n")
	syntax.Stdin = strings.NewReader(script)
	if out, err := syntax.CombinedOutput(); err != nil {
		t.Errorf("install.sh is not valid shell: %v\n%s", err, out)
	}

	status, manifest := fetch(t, h, agentHost, "/agent/latest.json")
	if status != 200 || !strings.Contains(manifest, `"version":"1.2.3"`) {
		t.Errorf("/agent/latest.json: %d %s", status, manifest)
	}
	if status, body := fetch(t, h, agentHost, "/agent/exposehub-linux-amd64"); status != 200 || body != "agent build for linux-amd64" {
		t.Errorf("binary: %d %q", status, body)
	}
	for _, path := range []string{"/agent/secret.txt", "/agent/../secret.txt", "/agent/%2e%2e/secret.txt", "/agent/"} {
		if status, body := fetch(t, h, agentHost, path); status == 200 || strings.Contains(body, "not for download") {
			t.Errorf("%s: status %d, body %q; want it refused", path, status, body)
		}
	}
	// Only the agent host serves downloads; on a tunnel address the path belongs to the tunnel.
	if status, _ := fetch(t, h, "someone."+tunnelDomain, "/install.sh"); status != http.StatusNotFound {
		t.Errorf("/install.sh on a tunnel address: status %d, want the tunnel's own 404", status)
	}
}

func TestWithoutDownloadsTheServerSaysSo(t *testing.T) {
	h := newHarness(t)
	status, body := fetch(t, h, agentHost, "/install.sh")
	if status != http.StatusNotFound || !strings.Contains(body, "no agent downloads") {
		t.Errorf("got %d %q", status, body)
	}
}

func TestABrokenReleaseStopsTheServerFromStarting(t *testing.T) {
	dir := releaseDir(t, "1.2.3")
	if err := os.Remove(filepath.Join(dir, "exposehub-linux-amd64")); err != nil {
		t.Fatal(err)
	}
	tokens, _ := server.ParseStaticTokens("alice:alice-token")
	_, err := server.New(server.Config{AgentHost: agentHost, TunnelDomains: []string{tunnelDomain}, AgentDownloads: dir}, tokens, quiet)
	if err == nil {
		t.Error("New accepted a release whose files are missing")
	}
}

func TestAnOutdatedAgentIsToldOnce(t *testing.T) {
	h := newHarness(t, func(cfg *server.Config) { cfg.AgentDownloads = releaseDir(t, "2.0.0") })

	for _, c := range []struct {
		version string
		told    bool
	}{{"1.0.0", true}, {"2.0.0", false}, {"dev", false}} {
		told := make(chan string, 4)
		connected := make(chan struct{}, 4)
		ctx, cancel := context.WithCancel(context.Background())
		done := make(chan struct{})
		go func() {
			defer close(done)
			_ = agent.Run(ctx, agent.Config{
				Server:       "ws://" + agentHost,
				Token:        "alice-token",
				Version:      c.version,
				Logger:       quiet,
				HTTPClient:   &http.Client{Transport: pinned(h.addr, nil)},
				Tunnels:      []agent.Tunnel{upstream(t, "web", echoPath())},
				OnConnect:    func([]proto.TunnelInfo) { connected <- struct{}{} },
				OnNewerAgent: func(offered string) { told <- offered },
			})
		}()
		select {
		case <-connected:
		case <-time.After(5 * time.Second):
			t.Fatalf("agent %s did not connect", c.version)
		}
		cancel()
		<-done
		close(told)
		var got []string
		for v := range told {
			got = append(got, v)
		}
		if want := fmt.Sprint(c.told); fmt.Sprint(len(got) == 1 && got[0] == "2.0.0") != want || len(got) > 1 {
			t.Errorf("agent %s was told %v; want told=%v exactly once", c.version, got, c.told)
		}
	}
}
