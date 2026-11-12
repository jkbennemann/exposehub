// Package cliconfig stores what "exposehub login" saves: the tunnel server and
// the agent token, so neither has to appear on a command line.
package cliconfig

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
)

// Config is the content of the agent's config file.
type Config struct {
	Server string `json:"server,omitempty"`
	Token  string `json:"token,omitempty"`
}

// Path returns where the config file lives: EXPOSEHUB_CONFIG if set, otherwise
// exposehub/config.json in the user's config directory.
func Path() (string, error) {
	if p := os.Getenv("EXPOSEHUB_CONFIG"); p != "" {
		return p, nil
	}
	dir, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "exposehub", "config.json"), nil
}

// Load reads the config file. A missing file is an empty config, not an error.
func Load() (Config, error) {
	var cfg Config
	path, err := Path()
	if err != nil {
		return cfg, err
	}
	body, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return cfg, nil
	}
	if err != nil {
		return cfg, err
	}
	if err := json.Unmarshal(body, &cfg); err != nil {
		return Config{}, errors.New(path + " is not valid; run exposehub login again")
	}
	return cfg, nil
}

// Save writes the config file, readable by its owner only.
func Save(cfg Config) (string, error) {
	path, err := Path()
	if err != nil {
		return "", err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return "", err
	}
	body, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return "", err
	}
	// Written under a temporary name first, so a crash cannot leave half a file.
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, append(body, '\n'), 0o600); err != nil {
		return "", err
	}
	return path, os.Rename(tmp, path)
}

// Remove deletes the config file. A missing file is not an error.
func Remove() (string, error) {
	path, err := Path()
	if err != nil {
		return "", err
	}
	if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
		return path, err
	}
	return path, nil
}
