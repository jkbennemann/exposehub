package main

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
	"unicode/utf8"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
)

func TestFolderTargetsAreToldApartFromHosts(t *testing.T) {
	folders := []string{"./dist", "../site/build", ".", "..", "~/sites/blog", "/var/www/site"}
	if runtime.GOOS == "windows" {
		folders = append(folders, `.\dist`, `C:\sites\blog`)
	}
	for _, target := range folders {
		if !isFolderTarget(target) {
			t.Errorf("%q should be a folder", target)
		}
	}
	for _, target := range []string{"8000", "localhost:3000", "dist", "mysite.test", "https://localhost:8443", "http://example.test/x"} {
		if isFolderTarget(target) {
			t.Errorf("%q should not be a folder", target)
		}
	}
}

func TestResolveFolderRefusesWhatShouldNotBePublished(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "page.html"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Mkdir(filepath.Join(dir, "dist"), 0o755); err != nil {
		t.Fatal(err)
	}

	got, err := resolveFolder("./dist", dir)
	if err != nil {
		t.Fatal(err)
	}
	want, _ := filepath.EvalSymlinks(filepath.Join(dir, "dist"))
	if got != want {
		t.Errorf("resolved %q, want %q", got, want)
	}

	home, err := os.UserHomeDir()
	if err != nil {
		t.Skip("no home directory")
	}
	for target, msg := range map[string]string{
		"./page.html": "is a file",
		"./missing":   "no such folder",
		home:          "home folder",
		"~/":          "home folder",
		filepath.VolumeName(dir) + string(filepath.Separator): "root of the file system",
	} {
		if _, err := resolveFolder(target, dir); err == nil || !strings.Contains(err.Error(), msg) {
			t.Errorf("resolveFolder(%q) = %v, want an error saying %q", target, err, msg)
		}
	}
}

// site lays out a published folder next to things that must stay private.
func site(t *testing.T) string {
	t.Helper()
	base := t.TempDir()
	files := map[string]string{
		"site/index.html":               "<h1>home</h1>",
		"site/app.js":                   "console.log(1)",
		"site/docs/index.html":          "docs",
		"site/assets/logo.svg":          "<svg/>",
		"site/.env":                     "SECRET=1",
		"site/.git/config":              "[core]",
		"site/assets/.secret":           "x",
		"site/.well-known/security.txt": "Contact: a@example.test",
		"private.txt":                   "outside the folder",
	}
	for name, body := range files {
		p := filepath.Join(base, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if runtime.GOOS != "windows" {
		if err := os.Symlink(filepath.Join(base, "private.txt"), filepath.Join(base, "site", "leak.txt")); err != nil {
			t.Fatal(err)
		}
		if err := os.Symlink(base, filepath.Join(base, "site", "up")); err != nil {
			t.Fatal(err)
		}
	}
	return filepath.Join(base, "site")
}

func folderGet(t *testing.T, h http.Handler, method, target string) (int, string) {
	t.Helper()
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest(method, target, nil))
	body, _ := io.ReadAll(rec.Result().Body)
	return rec.Code, string(body)
}

func TestFolderHandlerServesTheFolderAndNothingElse(t *testing.T) {
	root, err := os.OpenRoot(site(t))
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	h := folderHandler(root.FS())

	for path, want := range map[string]string{
		"/":                         "<h1>home</h1>",
		"/app.js":                   "console.log(1)",
		"/docs/":                    "docs",
		"/assets/logo.svg":          "<svg/>",
		"/.well-known/security.txt": "Contact: a@example.test",
	} {
		if code, body := folderGet(t, h, http.MethodGet, path); code != http.StatusOK || body != want {
			t.Errorf("GET %s = %d %q, want 200 %q", path, code, body, want)
		}
	}

	// Hidden files, a folder without index.html, ways out of the folder.
	for _, path := range []string{
		"/.env", "/.git/config", "/assets/.secret", "/%2eenv", "/assets/", "/../private.txt",
		"/%2e%2e/private.txt", "/assets/..%2f..%2fprivate.txt", "/leak.txt", "/up/private.txt",
	} {
		if code, body := folderGet(t, h, http.MethodGet, path); code == http.StatusOK || strings.Contains(body, "outside the folder") || strings.Contains(body, "SECRET") {
			t.Errorf("GET %s = %d %q, want it refused", path, code, body)
		}
	}

	if code, _ := folderGet(t, h, http.MethodPost, "/"); code != http.StatusMethodNotAllowed {
		t.Errorf("POST / = %d, want 405", code)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/app.js", nil))
	if got := rec.Header().Get("X-Content-Type-Options"); got != "nosniff" {
		t.Errorf("X-Content-Type-Options = %q, want nosniff", got)
	}
}

func TestFolderTargetsBecomeTunnelsWithAFolder(t *testing.T) {
	dir := t.TempDir()
	if err := os.Mkdir(filepath.Join(dir, "dist"), 0o755); err != nil {
		t.Fatal(err)
	}
	tunnels, err := buildHTTPTunnels([]string{"docs=./dist"}, tunnelOptions{BaseDir: dir})
	if err != nil {
		t.Fatal(err)
	}
	want, _ := filepath.EvalSymlinks(filepath.Join(dir, "dist"))
	if tunnels[0].Folder != want || tunnels[0].Upstream != nil || tunnels[0].Subdomain != "docs" {
		t.Errorf("got folder %q upstream %v subdomain %q", tunnels[0].Folder, tunnels[0].Upstream, tunnels[0].Subdomain)
	}

	// In a project file, a relative folder starts at the file's own folder.
	project := filepath.Join(dir, "exposehub.yml")
	if err := os.WriteFile(project, []byte("tunnels:\n  docs:\n    target: ./dist\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	t.Chdir(t.TempDir())
	tunnels, err = loadProject(project, nil, env(nil))
	if err != nil {
		t.Fatal(err)
	}
	if tunnels[0].Folder != want {
		t.Errorf("project folder = %q, want %q", tunnels[0].Folder, want)
	}
}

// Review findings of 0.4.0: hidden folders, folders above home, home written
// in another case, and the system's folders were served.
func TestResolveFolderRefusesHiddenFoldersHomeAndWhatHoldsIt(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("home and system folders differ on Windows")
	}
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(base, "home", "ada")
	for _, d := range []string{".ssh", "site/dist", "Library/Keychains", "work/.cache/site", "../../.keys"} {
		if err := os.MkdirAll(filepath.Join(home, filepath.FromSlash(d)), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("HOME", home)

	if _, err := resolveFolder("~/site/dist", ""); err != nil {
		t.Errorf("a site folder in home was refused: %v", err)
	}
	for target, msg := range map[string]string{
		"~/.ssh":                     "hidden folder",
		"~/work/.cache/site":         "hidden folder",
		filepath.Join(base, ".keys"): "hidden folder",
		filepath.Join(base, "home"):  "holds your home folder",
		base:                         "holds your home folder",
		"~/Library/Keychains":        "folder of the system",
		"/etc":                       "folder of the system",
	} {
		if _, err := resolveFolder(target, home); err == nil || !strings.Contains(err.Error(), msg) {
			t.Errorf("resolveFolder(%q) = %v, want an error saying %q", target, err, msg)
		}
	}
	// On a disk that ignores case, home written otherwise is still home.
	if upper := strings.ToUpper(home); upper != home {
		if _, err := os.Stat(upper); err == nil {
			if _, err := resolveFolder(upper, ""); err == nil || !strings.Contains(err.Error(), "home folder") {
				t.Errorf("resolveFolder(%q) = %v, want home refused", upper, err)
			}
		}
	}
}

// Outside the home folder, a hidden folder further up the path (a
// temporary folder such as /work/.tmp) does not make its contents hidden.
func TestFoldersBelowAHiddenFolderOutsideHomeAreServed(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("home differs on Windows")
	}
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("HOME", filepath.Join(base, "home"))
	site := filepath.Join(base, ".tmp", "build", "site")
	for _, d := range []string{filepath.Join(base, "home"), site} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := resolveFolder(site, ""); err != nil {
		t.Errorf("a folder below %s was refused: %v", filepath.Join(base, ".tmp"), err)
	}
	if _, err := resolveFolder(filepath.Join(base, ".tmp"), ""); err == nil {
		t.Error("the hidden folder itself was accepted")
	}
}

// Review finding: a folder called index.html was listed at /.
func TestAnIndexFolderIsNotListed(t *testing.T) {
	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, "index.html"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "index.html", "secret.txt"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	if code, body := folderGet(t, folderHandler(root.FS()), http.MethodGet, "/"); code == http.StatusOK || strings.Contains(body, "secret.txt") {
		t.Errorf("GET / = %d %q, want no listing", code, body)
	}
}

// Review finding: the folder's local port answered anyone on the machine,
// past the tunnel's password. Only the address with its random path works.
func TestTheLocalFolderPortAnswersOnlyWithItsSecretPath(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "index.html"), []byte("home"), 0o644); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	u, err := serveFolder(ctx, dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(u.Path) < 20 {
		t.Fatalf("the secret path %q is too short", u.Path)
	}
	get := func(url string) (int, string) {
		resp, err := http.Get(url)
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		body, _ := io.ReadAll(resp.Body)
		return resp.StatusCode, string(body)
	}
	for _, path := range []string{"/", "/index.html", "/x" + u.Path[2:] + "/"} {
		if code, body := get("http://" + u.Host + path); code != http.StatusNotFound || body == "home" {
			t.Errorf("GET %s without the secret = %d %q, want 404", path, code, body)
		}
	}
	if code, body := get(u.String() + "/"); code != http.StatusOK || body != "home" {
		t.Errorf("GET with the secret = %d %q", code, body)
	}

	// Two folders get different secrets.
	v, err := serveFolder(ctx, dir)
	if err != nil {
		t.Fatal(err)
	}
	if v.Path == u.Path {
		t.Error("two folders got the same secret path")
	}
}

// Review finding: visitors choose request paths, up to about a megabyte, and
// the events carried them whole into a program's or a model's input.
func TestRequestEventsCarryAShortenedPath(t *testing.T) {
	long := "/" + strings.Repeat("ä", 5000)
	e := newRequestEvent(agent.Request{Tunnel: "t", Method: "GET", Path: long, Status: 200}, time.Now())
	if len(e.Path) > maxEventPath+len("…") || !strings.HasSuffix(e.Path, "…") || !utf8.ValidString(e.Path) {
		t.Errorf("path of %d bytes, valid %v: %q", len(e.Path), utf8.ValidString(e.Path), e.Path[:20])
	}
	if got := newRequestEvent(agent.Request{Path: "/ok"}, time.Now()).Path; got != "/ok" {
		t.Errorf("a short path became %q", got)
	}
}
