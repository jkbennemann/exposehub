//go:build unix

package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// below makes dir/app/src and returns it: where a developer would run
// "exposehub start" in a project without a file of its own.
func below(t *testing.T, dir string) string {
	t.Helper()
	deep := filepath.Join(dir, "app", "src")
	if err := os.MkdirAll(deep, 0o755); err != nil {
		t.Fatal(err)
	}
	return deep
}

func chmod(t *testing.T, path string, mode os.FileMode) {
	t.Helper()
	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
}

func wantRefused(t *testing.T, from, path, why string) {
	t.Helper()
	got, err := findProjectFile(from)
	if err == nil {
		t.Fatalf("found %q; want %s refused", got, path)
	}
	for _, want := range []string{path, why, "--file"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q does not mention %q", err, want)
		}
	}
}

func TestAProjectFileOfAnotherAccountIsNotUsed(t *testing.T) {
	if os.Getuid() == 0 {
		t.Skip("root's files are trusted, so running as root cannot show another account")
	}
	path := writeProject(t, project)
	uid := currentUID()
	currentUID = func() int { return uid + 1 }
	t.Cleanup(func() { currentUID = os.Getuid })

	wantRefused(t, below(t, filepath.Dir(path)), path, "another account")
}

// Another account can put a file into a directory everyone may write, such as
// a shared folder that is not sticky.
func TestAProjectFileInADirectoryEveryoneCanChangeIsNotUsed(t *testing.T) {
	path := writeProject(t, project)
	shared := filepath.Dir(path)
	chmod(t, shared, 0o777)

	wantRefused(t, below(t, shared), path, "every account can change")
}

func TestAProjectFileEveryoneCanChangeIsNotUsed(t *testing.T) {
	path := writeProject(t, project)
	chmod(t, path, 0o666)

	wantRefused(t, below(t, filepath.Dir(path)), path, "changed by every account")
}

func TestALinkToAProjectFileInADirectoryEveryoneCanChangeIsNotUsed(t *testing.T) {
	target := writeProject(t, project)
	chmod(t, filepath.Dir(target), 0o777)
	home := t.TempDir()
	link := filepath.Join(home, "exposehub.yml")
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}

	wantRefused(t, below(t, home), link, "every account can change")
}

// In a sticky directory such as /tmp, others cannot replace the developer's
// own file, so it is used.
func TestOwnProjectFileInAStickySharedDirectoryIsUsed(t *testing.T) {
	path := writeProject(t, project)
	// os.ModeSticky, not 0o1000: Go keeps the sticky bit outside the
	// permission bits, so 0o1777 would make a plain 0777 directory.
	chmod(t, filepath.Dir(path), os.ModeSticky|0o777)
	if info, err := os.Stat(filepath.Dir(path)); err != nil || info.Mode()&os.ModeSticky == 0 {
		t.Fatalf("could not make a sticky directory: %v", err)
	}

	got, err := findProjectFile(below(t, filepath.Dir(path)))
	if err != nil || got != path {
		t.Fatalf("got %q, %v; want %q", got, err, path)
	}
}

// A refused file can still be given with --file, which loads it as it is.
func TestARefusedProjectFileCanStillBeGivenExplicitly(t *testing.T) {
	path := writeProject(t, project)
	chmod(t, path, 0o666)
	if _, err := findProjectFile(filepath.Dir(path)); err == nil {
		t.Fatal("the file was not refused")
	}
	if _, err := loadProject(path, []string{"shop"}, env(map[string]string{"SHOP_PASSWORD": "pw"})); err != nil {
		t.Fatalf("loading it explicitly: %v", err)
	}
}
