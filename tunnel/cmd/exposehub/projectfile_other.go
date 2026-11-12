//go:build !unix

package main

// trustedProjectFile accepts every found project file. Projects on Windows
// normally sit in the account's own profile, which other accounts cannot
// write; checking the owner of files elsewhere would need the file's
// security descriptor, which this build does not read yet.
func trustedProjectFile(string) error { return nil }
