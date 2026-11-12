//go:build unix

package main

import (
	"errors"
	"os"
	"path/filepath"
	"syscall"
)

// currentUID is the account running exposehub; tests replace it.
var currentUID = os.Getuid

// trustedProjectFile accepts a found project file only if no other account
// could have written it, much as git does for repositories: the file (and
// the link to it, if it is one) and its directory belong to this account or
// to root, not everyone may change the file, and not everyone may add or
// replace files in its directory unless that directory is sticky, as /tmp
// is, which lets each account change only its own files.
func trustedProjectFile(path string) error {
	link, err := os.Lstat(path)
	if err != nil {
		return err
	}
	file, err := os.Stat(path)
	if err != nil {
		return err
	}
	real, err := filepath.EvalSymlinks(path)
	if err != nil {
		return err
	}
	infos := []os.FileInfo{link, file}
	var dirs []os.FileInfo
	for _, d := range []string{filepath.Dir(path), filepath.Dir(real)} {
		info, err := os.Stat(d)
		if err != nil {
			return err
		}
		dirs = append(dirs, info)
	}
	for _, info := range append(infos, dirs...) {
		st, ok := info.Sys().(*syscall.Stat_t)
		if !ok {
			return errors.New("has an owner that cannot be checked")
		}
		if int(st.Uid) != currentUID() && st.Uid != 0 {
			return errors.New("belongs to another account")
		}
	}
	if file.Mode().Perm()&0o002 != 0 {
		return errors.New("can be changed by every account")
	}
	for _, dir := range dirs {
		if dir.Mode().Perm()&0o002 != 0 && dir.Mode()&os.ModeSticky == 0 {
			return errors.New("is in a directory every account can change")
		}
	}
	return nil
}
