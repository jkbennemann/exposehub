package main

import (
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"io/fs"
	"net"
	"net/http"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// isFolderTarget reports whether a target names a local folder rather than a
// port, a host or a URL: it starts with ./, ../ or ~/, or is an absolute path.
// A bare name such as "dist" stays a host name, as it always was.
func isFolderTarget(target string) bool {
	t := strings.TrimSpace(target)
	if t == "." || t == ".." {
		return true
	}
	for _, prefix := range []string{"./", "../", `.\`, `..\`, "~/", `~\`} {
		if strings.HasPrefix(t, prefix) {
			return true
		}
	}
	if strings.Contains(t, "://") {
		return false
	}
	return filepath.IsAbs(t)
}

// resolveFolder turns a folder target into an absolute path, relative to
// base when it is relative. It refuses what is not a folder, and folders
// that hold far more, or other, than anyone means to publish: hidden ones
// such as ~/.ssh, the home folder and every folder above it, and the
// system's own (/etc, ~/Library and the like).
func resolveFolder(target, base string) (string, error) {
	p := strings.TrimSpace(target)
	if p == "~" || strings.HasPrefix(p, "~/") || strings.HasPrefix(p, `~\`) {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", fmt.Errorf("%s: %w", target, err)
		}
		p = filepath.Join(home, p[1:])
	}
	if !filepath.IsAbs(p) {
		if base == "" {
			wd, err := os.Getwd()
			if err != nil {
				return "", err
			}
			base = wd
		}
		p = filepath.Join(base, p)
	}
	p, err := filepath.Abs(p)
	if err != nil {
		return "", err
	}
	if p, err = filepath.EvalSymlinks(p); err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return "", fmt.Errorf("%s: there is no such folder", target)
		}
		return "", fmt.Errorf("%s: %w", target, err)
	}
	info, err := os.Stat(p)
	if err != nil {
		return "", fmt.Errorf("%s: %w", target, err)
	}
	if !info.IsDir() {
		return "", fmt.Errorf("%s is a file; give the folder that holds it", target)
	}
	if filepath.Dir(p) == p {
		return "", fmt.Errorf("%s is the root of the file system; give the folder you mean to publish, such as ./dist", target)
	}
	home, homeErr := os.UserHomeDir()
	// Hidden folders hold settings and keys: ~/.ssh, ~/.aws, ~/.config. In
	// the home folder no part of the path may be hidden; elsewhere, as under
	// a temporary folder such as /work/.tmp, only the folder itself.
	hidden := []string{filepath.Base(p)}
	if homeErr == nil {
		if below, ok := partsBelow(p, home); ok {
			hidden = below
		}
	}
	for _, part := range hidden {
		if strings.HasPrefix(part, ".") {
			return "", fmt.Errorf("%s is a hidden folder or inside one (%s); those hold settings and keys, not sites", target, part)
		}
	}
	if homeErr == nil {
		// Compared as files, not as strings: macOS disks ignore case, and
		// /Users/ada, /users/ada and a symbolic link to it are one folder.
		for dir := home; ; dir = filepath.Dir(dir) {
			if sameFile(dir, p) {
				if dir == home {
					return "", fmt.Errorf("%s is your home folder; give the folder you mean to publish, such as ./dist", target)
				}
				return "", fmt.Errorf("%s holds your home folder; give the folder you mean to publish, such as ./dist", target)
			}
			if filepath.Dir(dir) == dir {
				break
			}
		}
	}
	for _, system := range systemFolders(home) {
		if insideFolder(p, system) {
			return "", fmt.Errorf("%s is a folder of the system (%s); give the folder you mean to publish, such as ./dist", target, system)
		}
	}
	// Nor a folder that holds one, such as /var or /private on macOS.
	for _, held := range append(systemFolders(home), heldFolders()...) {
		if _, err := os.Stat(held); err == nil && insideFolder(held, p) {
			return "", fmt.Errorf("%s holds a folder of the system (%s); give the folder you mean to publish, such as ./dist", target, held)
		}
	}
	return p, nil
}

// heldFolders are folders whose contents may be published (the temporary
// folders, for example), but not the folders that hold them: /var holds
// every account's temporary files.
func heldFolders() []string {
	if runtime.GOOS == "windows" {
		return nil
	}
	return []string{"/var/log", "/var/lib", "/var/folders", "/private/var/log", "/private/var/folders"}
}

// systemFolders are folders whose contents are never a site.
func systemFolders(home string) []string {
	if runtime.GOOS == "windows" {
		var out []string
		for _, name := range []string{"SystemRoot", "ProgramData"} {
			if v := os.Getenv(name); v != "" {
				out = append(out, v)
			}
		}
		if home != "" {
			out = append(out, filepath.Join(home, "AppData"))
		}
		return out
	}
	out := []string{"/etc", "/proc", "/sys", "/dev", "/boot", "/root", "/private/etc", "/System", "/Library"}
	if home != "" {
		out = append(out, filepath.Join(home, "Library"))
	}
	return out
}

// partsBelow returns the names that lead from base down to dir, and whether
// dir lies at or below base at all.
func partsBelow(dir, base string) ([]string, bool) {
	var parts []string
	for d := dir; ; d = filepath.Dir(d) {
		if sameFile(d, base) {
			return parts, true
		}
		if filepath.Dir(d) == d {
			return nil, false
		}
		parts = append(parts, filepath.Base(d))
	}
}

// sameFile reports whether a and b name the same existing file or folder.
func sameFile(a, b string) bool {
	ai, err := os.Stat(a)
	if err != nil {
		return false
	}
	bi, err := os.Stat(b)
	return err == nil && os.SameFile(ai, bi)
}

// insideFolder reports whether dir is base or lies below it.
func insideFolder(dir, base string) bool {
	bi, err := os.Stat(base)
	if err != nil {
		return false
	}
	for d := dir; ; d = filepath.Dir(d) {
		if di, err := os.Stat(d); err == nil && os.SameFile(di, bi) {
			return true
		}
		if filepath.Dir(d) == d {
			return false
		}
	}
}

// serveFolder serves dir on a free port of this machine until ctx ends and
// returns its address, for a tunnel to send its visitors to. The address
// ends in a random path that only the tunnel knows: without it the port
// answers 404, so another program or account on this machine, or a web page
// that guesses the port, cannot read the folder past the tunnel's password
// and network limits.
func serveFolder(ctx context.Context, dir string) (*url.URL, error) {
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		_ = root.Close()
		return nil, err
	}
	secret := "/" + rand.Text()
	srv := &http.Server{Handler: http.StripPrefix(secret, folderHandler(root.FS())), ReadHeaderTimeout: 10 * time.Second}
	go func() { _ = srv.Serve(ln) }()
	go func() {
		<-ctx.Done()
		_ = srv.Close()
		_ = root.Close()
	}()
	return &url.URL{Scheme: "http", Host: ln.Addr().String(), Path: secret}, nil
}

// folderHandler serves the files of fsys, read only. fsys comes from an
// os.Root, so neither ../ nor a symbolic link leads out of the folder. Names
// starting with a dot (.env, .git) are never served, .well-known excepted, and
// a folder without an index.html is not listed: a folder published by mistake
// shows as little as possible.
func folderHandler(fsys fs.FS) http.Handler {
	files := http.FileServerFS(fsys)
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet && r.Method != http.MethodHead {
			w.Header().Set("Allow", "GET, HEAD")
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}
		clean := path.Clean("/" + r.URL.Path)
		for _, segment := range strings.Split(clean, "/") {
			// On Windows, GIT~1 can be the short name of .git.
			if (strings.HasPrefix(segment, ".") && segment != ".well-known") || (runtime.GOOS == "windows" && strings.Contains(segment, "~")) {
				http.NotFound(w, r)
				return
			}
		}
		name := strings.TrimPrefix(clean, "/")
		if name == "" {
			name = "."
		}
		if info, err := fs.Stat(fsys, name); err == nil && info.IsDir() {
			// Only a real index.html: a folder of that name would be listed.
			if index, err := fs.Stat(fsys, path.Join(name, "index.html")); err != nil || !index.Mode().IsRegular() {
				http.NotFound(w, r)
				return
			}
		}
		w.Header().Set("X-Content-Type-Options", "nosniff")
		files.ServeHTTP(w, r)
	})
}
