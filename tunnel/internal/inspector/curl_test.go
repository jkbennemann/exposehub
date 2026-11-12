package inspector

import (
	"bytes"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// curlBuilder returns the part of the shipped app.js that builds the "Copy as
// curl" command: shellQuote, CONTROL and curlCommand, which sit together
// right before copyCurl.
func curlBuilder(t *testing.T) string {
	t.Helper()
	src, err := uiFiles.ReadFile("ui/app.js")
	if err != nil {
		t.Fatal(err)
	}
	js := string(src)
	start := strings.Index(js, "function shellQuote(")
	end := strings.Index(js, "async function copyCurl(")
	if start < 0 || end < start {
		t.Fatal("app.js: shellQuote and curlCommand are not where this test expects them, right before copyCurl")
	}
	return js[start:end]
}

// A visitor chooses the method, path, headers and body of a request. When the
// developer copies it as curl and pastes it into a shell, none of that may run
// as a command, and curl must send exactly the captured request.
func TestCopyAsCurlRunsNothingButCurl(t *testing.T) {
	node, err := exec.LookPath("node")
	if err != nil {
		t.Skip("node is not installed; it runs the inspector's JavaScript")
	}
	sh, err := exec.LookPath("sh")
	if err != nil {
		t.Skip("no POSIX shell to paste the command into")
	}

	body := "@/etc/passwd\n$(touch PWNED-BODY)"
	ex := Exchange{
		Method: "X|touch$IFS'PWNED-METHOD'|",
		URL:    "/hook[1-2]?q=$(touch$IFS'PWNED-URL')",
		Host:   "demo.tunnel.test",
		RequestHeader: map[string][]string{
			"X-Forwarded-Proto": {"https"},
			"X-Note":            {"it's `touch PWNED-HEADER`"},
			"X-Csi":             {"a\u009b201~b"},
		},
		RequestBody: Body{Data: []byte(body), Size: int64(len(body))},
	}
	payload, err := json.Marshal(ex)
	if err != nil {
		t.Fatal(err)
	}

	dir := t.TempDir()
	script := filepath.Join(dir, "build.js")
	code := curlBuilder(t) + "\nprocess.stdout.write(JSON.stringify(curlCommand(JSON.parse(process.argv[2]))));\n"
	if err := os.WriteFile(script, []byte(code), 0o600); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command(node, script, string(payload)).Output()
	if err != nil {
		t.Fatalf("node: %v", err)
	}
	var built struct{ Command, Note string }
	if err := json.Unmarshal(out, &built); err != nil {
		t.Fatalf("node printed %q: %v", out, err)
	}

	// A stand-in curl that prints the arguments it was given.
	bin := filepath.Join(dir, "bin")
	if err := os.Mkdir(bin, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(bin, "curl"), []byte("#!/bin/sh\nfor a in \"$@\"; do printf '%s\\0' \"$a\"; done\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	work := filepath.Join(dir, "work")
	if err := os.Mkdir(work, 0o700); err != nil {
		t.Fatal(err)
	}
	cmd := exec.Command(sh, "-c", built.Command)
	cmd.Dir = work
	cmd.Env = []string{"PATH=" + bin + ":/usr/bin:/bin"}
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	argv, err := cmd.Output()
	if left, _ := os.ReadDir(work); len(left) > 0 {
		t.Errorf("pasting the command ran something besides curl (it left %s): %s", left[0].Name(), built.Command)
	}
	if err != nil {
		t.Fatalf("the shell failed on %s: %v %s", built.Command, err, stderr.String())
	}
	got := strings.Split(strings.TrimSuffix(string(argv), "\x00"), "\x00")
	want := []string{
		"--globoff",
		"-X", "X|touch$IFS'PWNED-METHOD'|",
		"https://demo.tunnel.test/hook[1-2]?q=$(touch$IFS'PWNED-URL')",
		"-H", "X-Note: it's `touch PWNED-HEADER`",
		"--data-raw", body,
	}
	if !slices.Equal(got, want) {
		t.Errorf("curl got\n%q\nwant\n%q\nfrom: %s", got, want, built.Command)
	}
	if !strings.Contains(built.Note, "control characters") {
		t.Errorf("note %q does not say a header was left out", built.Note)
	}
}
