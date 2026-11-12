package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
)

func TestSeveralTargetsBecomeSeveralTunnels(t *testing.T) {
	tunnels, err := buildHTTPTunnels([]string{"shop=8000", "api=localhost:3000", "9000"}, tunnelOptions{Auth: []string{"ada:s3cret"}, Allow: []string{"Office VPN"}})
	if err != nil {
		t.Fatal(err)
	}
	if len(tunnels) != 3 {
		t.Fatalf("got %d tunnels", len(tunnels))
	}
	want := []struct{ name, sub, upstream string }{
		{"shop", "shop", "http://localhost:8000"},
		{"api", "api", "http://localhost:3000"},
		{"tunnel-3", "", "http://localhost:9000"},
	}
	for i, w := range want {
		got := tunnels[i]
		if got.Name != w.name || got.Subdomain != w.sub || got.Upstream.String() != w.upstream {
			t.Errorf("tunnel %d = %s/%s/%s, want %+v", i, got.Name, got.Subdomain, got.Upstream, w)
		}
		if len(got.Auth) != 1 || len(got.Allow) != 1 {
			t.Errorf("tunnel %d did not get the shared flags: %+v", i, got)
		}
	}
}

func TestOneTargetKeepsTheSubdomainFlag(t *testing.T) {
	tunnels, err := buildHTTPTunnels([]string{"8000"}, tunnelOptions{Subdomain: "shop"})
	if err != nil || len(tunnels) != 1 || tunnels[0].Subdomain != "shop" {
		t.Fatalf("got %+v, %v", tunnels, err)
	}
}

func TestTargetsThatCannotWork(t *testing.T) {
	cases := map[string]struct {
		targets []string
		opts    tunnelOptions
		want    string
	}{
		"nothing":               {nil, tunnelOptions{}, "give a target"},
		"subdomain for several": {[]string{"8000", "3000"}, tunnelOptions{Subdomain: "shop"}, "name each one"},
		"name and subdomain":    {[]string{"shop=8000"}, tunnelOptions{Subdomain: "x"}, "already names"},
		"bad name":              {[]string{"Sh op=8000"}, tunnelOptions{}, "not a valid name"},
		"no target":             {[]string{"shop="}, tunnelOptions{}, "needs a target"},
		"twice":                 {[]string{"shop=8000", "shop=3000"}, tunnelOptions{}, "used twice"},
		"too many":              {strings.Split("1 2 3 4 5 6 7 8 9 10 11", " "), tunnelOptions{}, "at most 10"},
		"bad password":          {[]string{"8000"}, tunnelOptions{Auth: []string{"ada"}}, "joined by a colon"},
	}
	for name, c := range cases {
		if _, err := buildHTTPTunnels(c.targets, c.opts); err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: got %v, want an error with %q", name, err, c.want)
		}
	}
}

func writeProject(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	path := filepath.Join(dir, "exposehub.yml")
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
	return path
}

func env(vars map[string]string) func(string) (string, bool) {
	return func(k string) (string, bool) { v, ok := vars[k]; return v, ok }
}

const project = `
tunnels:
  shop:
    target: 8000
    subdomain: shop
    auth: ["ada:${SHOP_PASSWORD}"]
    allow: ["Office VPN", 203.0.113.0/24]
  hooks:
    target: 8000
    subdomain: shop-hooks
    log: bodies
  docs:
    target: docs.test
    hostname: dev.example.com
    host_header: preserve
`

func TestAProjectFileOpensItsTunnelsInOrder(t *testing.T) {
	tunnels, err := loadProject(writeProject(t, project), nil, env(map[string]string{"SHOP_PASSWORD": "s3cret"}))
	if err != nil {
		t.Fatal(err)
	}
	if len(tunnels) != 3 || tunnels[0].Name != "shop" || tunnels[1].Name != "hooks" || tunnels[2].Name != "docs" {
		t.Fatalf("got %+v", tunnels)
	}
	shop := tunnels[0]
	if shop.Auth[0] != "ada:s3cret" || len(shop.Allow) != 2 || shop.Allow[1] != "203.0.113.0/24" {
		t.Errorf("shop = %+v", shop)
	}
	if !tunnels[1].LogBodies || tunnels[2].Hostname != "dev.example.com" || tunnels[2].HostHeader != "preserve" {
		t.Errorf("hooks/docs = %+v / %+v", tunnels[1], tunnels[2])
	}
}

func TestAProjectFileCanBeStartedInParts(t *testing.T) {
	tunnels, err := loadProject(writeProject(t, project), []string{"docs", "hooks"}, env(map[string]string{}))
	if err != nil {
		t.Fatal(err)
	}
	if len(tunnels) != 2 || tunnels[0].Name != "hooks" {
		t.Fatalf("got %+v", tunnels)
	}
	if _, err := loadProject(writeProject(t, project), []string{"nope"}, env(nil)); err == nil || !strings.Contains(err.Error(), "no tunnel called nope") {
		t.Errorf("unknown name: %v", err)
	}
}

func TestAMissingVariableIsAnErrorNotAnEmptyPassword(t *testing.T) {
	_, err := loadProject(writeProject(t, project), []string{"shop"}, env(map[string]string{}))
	if err == nil || !strings.Contains(err.Error(), "SHOP_PASSWORD is not set") {
		t.Fatalf("got %v", err)
	}
}

func TestATypoInTheProjectFileIsReported(t *testing.T) {
	_, err := loadProject(writeProject(t, "tunnels:\n  shop:\n    target: 8000\n    subdmain: shop\n"), nil, env(nil))
	if err == nil || !strings.Contains(err.Error(), "subdmain") {
		t.Fatalf("got %v", err)
	}
}

func TestTheProjectFileIsFoundInAParentDirectory(t *testing.T) {
	path := writeProject(t, project)
	deep := filepath.Join(filepath.Dir(path), "app", "src")
	if err := os.MkdirAll(deep, 0o755); err != nil {
		t.Fatal(err)
	}
	got, err := findProjectFile(deep)
	if err != nil || got != path {
		t.Fatalf("got %q, %v; want %q", got, err, path)
	}
}

func TestNotRunningNamesOnlyTheServicesThatAcceptNoConnection(t *testing.T) {
	up := httptest.NewServer(http.NotFoundHandler())
	defer up.Close()
	closed := httptest.NewServer(http.NotFoundHandler())
	closed.Close()

	var tunnels []agent.Tunnel
	for name, addr := range map[string]string{"up": up.URL, "down": closed.URL} {
		u, err := agent.ParseUpstream(addr)
		if err != nil {
			t.Fatal(err)
		}
		tunnels = append(tunnels, agent.Tunnel{Name: name, Upstream: u})
	}
	lines := notRunning(context.Background(), tunnels, func(name string) string { return name + "  " })
	if len(lines) != 1 || !strings.Contains(lines[0], "down  nothing answers at "+closed.URL) {
		t.Errorf("got %q, want one line about %s", lines, closed.URL)
	}
}
