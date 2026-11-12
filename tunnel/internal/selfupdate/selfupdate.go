// Package selfupdate replaces the running agent with the build its tunnel
// server hands out.
package selfupdate

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
)

// maxBinary bounds a download, so a broken server cannot fill the disk.
const maxBinary = 200 << 20

// Release is what the server's /agent/latest.json describes.
type Release struct {
	Version string          `json:"version"`
	Files   map[string]File `json:"files"`
}

// File is one build of the agent.
type File struct {
	Name   string `json:"name"`
	SHA256 string `json:"sha256"`
}

// Platform is the key of this machine's build in Release.Files.
func Platform() string { return runtime.GOOS + "-" + runtime.GOARCH }

// Fetch reads the release the server at base (an http(s) URL) offers.
func Fetch(ctx context.Context, client *http.Client, base string) (Release, error) {
	var rel Release
	body, err := get(ctx, client, base+"/agent/latest.json", 1<<20)
	if err != nil {
		return rel, err
	}
	if err := json.Unmarshal(body, &rel); err != nil {
		return rel, fmt.Errorf("the server's release description is not valid: %w", err)
	}
	if rel.Version == "" {
		return rel, errors.New("the server's release description names no version")
	}
	return rel, nil
}

// Install downloads f from the server at base, checks it, and puts it in
// place of target. Target is replaced in one step, so it is either the old
// agent or the new one, never a mix.
func Install(ctx context.Context, client *http.Client, base string, f File, target string) error {
	body, err := get(ctx, client, base+"/agent/"+f.Name, maxBinary)
	if err != nil {
		return err
	}
	sum := sha256.Sum256(body)
	if hex.EncodeToString(sum[:]) != f.SHA256 {
		return errors.New("the download is damaged (checksum mismatch); nothing was changed")
	}

	dir := filepath.Dir(target)
	tmp, err := os.CreateTemp(dir, ".exposehub-update-*")
	if err != nil {
		return fmt.Errorf("cannot write to %s (%w); update with the rights to write there, or install the agent in a directory you own", dir, err)
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(body); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Chmod(tmp.Name(), 0o755); err != nil {
		return err
	}
	if runtime.GOOS == "windows" {
		// Windows will not replace a running program, but it lets one be renamed.
		old := target + ".old"
		_ = os.Remove(old)
		if err := os.Rename(target, old); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	return os.Rename(tmp.Name(), target)
}

func get(ctx context.Context, client *http.Client, url string, limit int64) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	switch {
	case resp.StatusCode == http.StatusNotFound:
		return nil, errors.New("the server offers no agent downloads")
	case resp.StatusCode != http.StatusOK:
		return nil, fmt.Errorf("the server answered %s for %s", resp.Status, url)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(body)) > limit {
		return nil, fmt.Errorf("%s is larger than expected", url)
	}
	return body, nil
}
