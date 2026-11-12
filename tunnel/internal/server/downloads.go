package server

import (
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"text/template"
)

// AgentRelease is the set of agent builds the server hands out, as described
// by latest.json in the downloads directory.
type AgentRelease struct {
	Version string               `json:"version"`
	Files   map[string]AgentFile `json:"files"` // keyed by "<os>-<arch>"
}

// AgentFile is one build of the agent.
type AgentFile struct {
	Name   string `json:"name"`
	SHA256 string `json:"sha256"`
}

var (
	//go:embed install.sh.tmpl
	installScriptSource string
	installScript       = template.Must(template.New("install.sh").Parse(installScriptSource))

	// Only plain names: they end up in URLs, file paths and a shell script.
	safeName     = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]*$`)
	safeVersion  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9.+_-]*$`)
	safePlatform = regexp.MustCompile(`^[a-z0-9]+-[a-z0-9]+$`)
	sha256Hex    = regexp.MustCompile(`^[0-9a-f]{64}$`)
)

// LoadAgentRelease reads dir/latest.json and checks that every file it lists
// is there.
func LoadAgentRelease(dir string) (*AgentRelease, error) {
	body, err := os.ReadFile(filepath.Join(dir, "latest.json"))
	if err != nil {
		return nil, err
	}
	var rel AgentRelease
	if err := json.Unmarshal(body, &rel); err != nil {
		return nil, fmt.Errorf("latest.json: %w", err)
	}
	if !safeVersion.MatchString(rel.Version) {
		return nil, fmt.Errorf("latest.json: version %q is not valid", rel.Version)
	}
	if len(rel.Files) == 0 {
		return nil, errors.New("latest.json lists no files")
	}
	for platform, f := range rel.Files {
		if !safePlatform.MatchString(platform) || !safeName.MatchString(f.Name) || !sha256Hex.MatchString(f.SHA256) {
			return nil, fmt.Errorf("latest.json: the entry for %q is not valid", platform)
		}
		if _, err := os.Stat(filepath.Join(dir, f.Name)); err != nil {
			return nil, fmt.Errorf("latest.json lists %s: %w", f.Name, err)
		}
	}
	return &rel, nil
}

// serveDownloads answers /install.sh and /agent/... on the agent host. It
// reports false for any other path.
func (s *Server) serveDownloads(w http.ResponseWriter, r *http.Request) bool {
	switch {
	case r.URL.Path == "/install.sh":
	case strings.HasPrefix(r.URL.Path, "/agent/"):
	default:
		return false
	}
	if s.agents == nil {
		http.Error(w, "exposehub: this server offers no agent downloads", http.StatusNotFound)
		return true
	}
	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		w.Header().Set("Allow", "GET, HEAD")
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return true
	}
	// Names are not versioned, so nothing may be cached for long.
	w.Header().Set("Cache-Control", "no-cache")

	if r.URL.Path == "/install.sh" {
		s.serveInstallScript(w)
		return true
	}
	name := strings.TrimPrefix(r.URL.Path, "/agent/")
	if name == "latest.json" {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(s.agents)
		return true
	}
	// Only files the release lists; nothing else in the directory is reachable.
	for _, f := range s.agents.Files {
		if f.Name == name {
			w.Header().Set("Content-Type", "application/octet-stream")
			w.Header().Set("Content-Disposition", `attachment; filename="`+f.Name+`"`)
			http.ServeFile(w, r, filepath.Join(s.cfg.AgentDownloads, f.Name))
			return true
		}
	}
	http.NotFound(w, r)
	return true
}

func (s *Server) serveInstallScript(w http.ResponseWriter) {
	type file struct{ Platform, Name, SHA256 string }
	data := struct {
		BaseURL, Version string
		Files            []file
	}{BaseURL: s.cfg.publicURL(s.cfg.AgentHost), Version: s.agents.Version}
	for platform, f := range s.agents.Files {
		data.Files = append(data.Files, file{platform, f.Name, f.SHA256})
	}
	sort.Slice(data.Files, func(i, j int) bool { return data.Files[i].Platform < data.Files[j].Platform })

	w.Header().Set("Content-Type", "text/x-shellscript; charset=utf-8")
	if err := installScript.Execute(w, data); err != nil {
		s.log.Error("rendering install.sh failed", "err", err)
	}
}
