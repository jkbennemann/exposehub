package cliconfig

import (
	"os"
	"path/filepath"
	"testing"
)

func TestSaveLoadRemove(t *testing.T) {
	path := filepath.Join(t.TempDir(), "nested", "config.json")
	t.Setenv("EXPOSEHUB_CONFIG", path)

	if cfg, err := Load(); err != nil || cfg != (Config{}) {
		t.Fatalf("Load without a file = %+v, %v; want an empty config", cfg, err)
	}

	want := Config{Server: "https://connect.example.eu", Token: "eh_secret"}
	if _, err := Save(want); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if mode := info.Mode().Perm(); mode != 0o600 {
		t.Errorf("config file mode = %o, want 600: it holds a secret", mode)
	}
	if got, err := Load(); err != nil || got != want {
		t.Errorf("Load = %+v, %v; want %+v", got, err, want)
	}

	if _, err := Remove(); err != nil {
		t.Fatal(err)
	}
	if _, err := Remove(); err != nil {
		t.Errorf("removing a missing file returned %v, want nil", err)
	}
	if cfg, _ := Load(); cfg != (Config{}) {
		t.Errorf("Load after Remove = %+v, want empty", cfg)
	}
}

func TestLoadRejectsABrokenFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.json")
	t.Setenv("EXPOSEHUB_CONFIG", path)
	if err := os.WriteFile(path, []byte("{not json"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(); err == nil {
		t.Error("Load of a broken file returned no error")
	}
}
