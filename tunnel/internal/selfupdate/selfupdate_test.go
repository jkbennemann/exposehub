package selfupdate

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func serve(t *testing.T, binary []byte, sum string) *httptest.Server {
	t.Helper()
	rel := Release{Version: "9.9.9", Files: map[string]File{Platform(): {Name: "exposehub-test", SHA256: sum}}}
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/agent/latest.json":
			_ = json.NewEncoder(w).Encode(rel)
		case "/agent/exposehub-test":
			_, _ = w.Write(binary)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(ts.Close)
	return ts
}

func installed(t *testing.T) string {
	t.Helper()
	target := filepath.Join(t.TempDir(), "exposehub")
	if err := os.WriteFile(target, []byte("old agent"), 0o755); err != nil {
		t.Fatal(err)
	}
	return target
}

func TestUpdateReplacesTheAgent(t *testing.T) {
	binary := []byte("new agent")
	sum := sha256.Sum256(binary)
	ts := serve(t, binary, hex.EncodeToString(sum[:]))
	target := installed(t)

	rel, err := Fetch(context.Background(), ts.Client(), ts.URL)
	if err != nil {
		t.Fatal(err)
	}
	if rel.Version != "9.9.9" {
		t.Fatalf("version = %q", rel.Version)
	}
	if err := Install(context.Background(), ts.Client(), ts.URL, rel.Files[Platform()], target); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(target); string(got) != "new agent" {
		t.Errorf("target holds %q, want the new agent", got)
	}
	info, _ := os.Stat(target)
	if info.Mode().Perm()&0o100 == 0 {
		t.Errorf("the new agent is not executable (mode %o)", info.Mode().Perm())
	}
	leftovers, _ := filepath.Glob(filepath.Join(filepath.Dir(target), ".exposehub-update-*"))
	if len(leftovers) != 0 {
		t.Errorf("temporary files left behind: %v", leftovers)
	}
}

func TestADamagedDownloadChangesNothing(t *testing.T) {
	ts := serve(t, []byte("tampered"), strings.Repeat("0", 64))
	target := installed(t)

	rel, err := Fetch(context.Background(), ts.Client(), ts.URL)
	if err != nil {
		t.Fatal(err)
	}
	err = Install(context.Background(), ts.Client(), ts.URL, rel.Files[Platform()], target)
	if err == nil || !strings.Contains(err.Error(), "checksum") {
		t.Fatalf("Install returned %v, want a checksum error", err)
	}
	if got, _ := os.ReadFile(target); string(got) != "old agent" {
		t.Errorf("target holds %q, want the old agent untouched", got)
	}
}

func TestAServerWithoutDownloadsIsReportedPlainly(t *testing.T) {
	ts := httptest.NewServer(http.NotFoundHandler())
	t.Cleanup(ts.Close)
	_, err := Fetch(context.Background(), ts.Client(), ts.URL)
	if err == nil || !strings.Contains(err.Error(), "no agent downloads") {
		t.Errorf("Fetch returned %v", err)
	}
}
