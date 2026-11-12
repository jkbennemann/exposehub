package e2e

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// ask puts name to the internal certificate check and returns the status.
func ask(tb testing.TB, internal *httptest.Server, name string) int {
	tb.Helper()
	resp, err := http.Get(internal.URL + "/tls/ask?domain=" + url.QueryEscape(name))
	if err != nil {
		tb.Fatal(err)
	}
	resp.Body.Close()
	return resp.StatusCode
}

// internalOf serves the internal endpoints of the server cp runs.
func internalOf(tb testing.TB, cp *fakeControlPlane) *httptest.Server {
	tb.Helper()
	cp.mu.Lock()
	srv := cp.srv
	cp.mu.Unlock()
	ts := httptest.NewServer(srv.InternalHandler())
	tb.Cleanup(ts.Close)
	return ts
}

func TestTLSAskAllowsOnlyNamesTheServerServes(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_acme", 7, "acme")
	cp.connectDomain("dev.acme.example", 7)
	h := cp.connect(t, "")
	internal := internalOf(t, cp)

	// Wait for the first snapshot, which brings the connected domain.
	deadline := time.Now().Add(5 * time.Second)
	for ask(t, internal, "dev.acme.example") != http.StatusOK {
		if time.Now().After(deadline) {
			t.Fatal("the connected domain was never allowed")
		}
		time.Sleep(20 * time.Millisecond)
	}

	live := h.start(t, "eh_acme", shop(t)).host()

	for name, want := range map[string]int{
		"the agent host":                  http.StatusOK,
		"CONNECT.Tunnel.Test":             http.StatusOK,
		"connect.tunnel.test:443":         http.StatusOK,
		"Dev.Acme.Example.":               http.StatusOK,
		"unknown.example":                 http.StatusForbidden,
		"acme.example":                    http.StatusForbidden,
		"x.dev.acme.example":              http.StatusForbidden,
		"x." + tunnelDomain + ".evil.com": http.StatusForbidden,
		"dev.acme.example.evil.com":       http.StatusForbidden,
		live:                              http.StatusForbidden, // only with the option
		"nobody-here." + tunnelDomain:     http.StatusForbidden,
		tunnelDomain:                      http.StatusForbidden,
	} {
		if name == "the agent host" {
			name = agentHost
		}
		if got := ask(t, internal, name); got != want {
			t.Errorf("ask(%q) = %d, want %d", name, got, want)
		}
	}

	resp, err := http.Get(internal.URL + "/tls/ask")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode == http.StatusOK {
		t.Error("a question without a domain was answered yes")
	}
}

func TestTLSAskAllowsLiveTunnelNamesWhenEnabled(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_acme", 7, "acme")
	h := cp.connect(t, "", func(c *server.Config) { c.TLSAskTunnelNames = true; c.GracePeriod = time.Hour })
	internal := internalOf(t, cp)

	a := h.start(t, "eh_acme", shop(t))
	live := a.host()
	for name, want := range map[string]int{
		live:                              http.StatusOK,
		"nobody-here." + tunnelDomain:     http.StatusForbidden,
		"a.b." + tunnelDomain:             http.StatusForbidden,
		live + ".evil.com":                http.StatusForbidden,
		"x." + tunnelDomain + ".evil.com": http.StatusForbidden,
	} {
		if got := ask(t, internal, name); got != want {
			t.Errorf("ask(%q) = %d, want %d", name, got, want)
		}
	}

	// A name in its grace period still qualifies: the agent is coming back.
	a.stop()
	if got := ask(t, internal, live); got != http.StatusOK {
		t.Errorf("ask(%q) during the grace period = %d, want 200", live, got)
	}
}

func TestHealthz(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.connect(t, "")
	resp, err := http.Get(internalOf(t, cp).URL + "/healthz")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Errorf("healthz = %d", resp.StatusCode)
	}
}

func TestTLSAskBudgetsNewTunnelNamesPerAccount(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_churn", 7, "acme")
	cp.issue("eh_other", 8, "other")
	cp.connectDomain("dev.acme.example", 7)
	h := cp.connect(t, "", func(c *server.Config) { c.TLSAskTunnelNames = true; c.TLSAskNameLimit = 3 })
	internal := internalOf(t, cp)

	var mine []agent.Tunnel
	for i := range 4 {
		tun := shop(t)
		tun.Name, tun.Subdomain = fmt.Sprintf("t%d", i), fmt.Sprintf("n%d", i)
		mine = append(mine, tun)
	}
	h.start(t, "eh_churn", mine...)
	theirs := shop(t)
	theirs.Subdomain = "m0"
	h.start(t, "eh_other", theirs)

	for _, c := range []struct {
		name string
		want int
	}{
		{"n0." + tunnelDomain, http.StatusOK},
		{"n1." + tunnelDomain, http.StatusOK},
		{"n2." + tunnelDomain, http.StatusOK},
		{"n3." + tunnelDomain, http.StatusForbidden}, // the account's fourth new name
		{"n0." + tunnelDomain, http.StatusOK},        // asked again: no new certificate
		{"m0." + tunnelDomain, http.StatusOK},        // another account has its own budget
		{agentHost, http.StatusOK},
		{"dev.acme.example", http.StatusOK}, // verified domains are not budgeted
	} {
		if c.name == "dev.acme.example" {
			// The connected domain arrives with the first snapshot.
			deadline := time.Now().Add(5 * time.Second)
			for ask(t, internal, c.name) != http.StatusOK && time.Now().Before(deadline) {
				time.Sleep(20 * time.Millisecond)
			}
		}
		if got := ask(t, internal, c.name); got != c.want {
			t.Errorf("ask(%q) = %d, want %d", c.name, got, c.want)
		}
	}
}

func TestTLSAskBudgetsNewTunnelNamesForTheWholeInstall(t *testing.T) {
	cp := newFakeControlPlane(t)
	for team := range 4 {
		cp.issue(fmt.Sprintf("eh_team%d", team), 10+team, "team")
	}
	h := cp.connect(t, "", func(c *server.Config) {
		c.TLSAskTunnelNames, c.TLSAskNameLimit, c.TLSAskTotalLimit = true, 3, 8
	})
	internal := internalOf(t, cp)

	// Four teams with three names each, every one within its own budget.
	var names []string
	for team := range 4 {
		var tunnels []agent.Tunnel
		for i := range 3 {
			tun := shop(t)
			tun.Name, tun.Subdomain = fmt.Sprintf("t%d", i), fmt.Sprintf("team%d-n%d", team, i)
			names = append(names, tun.Subdomain+"."+tunnelDomain)
			tunnels = append(tunnels, tun)
		}
		h.start(t, fmt.Sprintf("eh_team%d", team), tunnels...)
	}
	approved := 0
	for _, name := range names {
		if ask(t, internal, name) == http.StatusOK {
			approved++
		}
	}
	if approved != 8 {
		t.Errorf("%d of %d names approved, want the install's 8", approved, len(names))
	}
	// Names approved before the budget ran out still are.
	if got := ask(t, internal, names[0]); got != http.StatusOK {
		t.Errorf("ask(%q) after the budget ran out = %d, want 200", names[0], got)
	}
	if got := ask(t, internal, agentHost); got != http.StatusOK {
		t.Errorf("the agent host = %d, want 200", got)
	}
}

// Let's Encrypt counts new certificates per registered domain, and two tunnel
// domains of one install can share one. The install's budget therefore covers
// all tunnel domains together, not each on its own.
func TestTheInstallsCertificateBudgetCoversAllTunnelDomainsTogether(t *testing.T) {
	const second = "eu." + tunnelDomain
	cp := newFakeControlPlane(t)
	for team := range 4 {
		cp.issue(fmt.Sprintf("eh_team%d", team), 10+team, "team")
	}
	h := cp.connect(t, "", func(c *server.Config) {
		c.TunnelDomains = append(c.TunnelDomains, second)
		c.TLSAskTunnelNames, c.TLSAskNameLimit, c.TLSAskTotalLimit = true, 4, 6
	})
	internal := internalOf(t, cp)

	// Each team opens two names under each tunnel domain: 16 names in all,
	// every team within its own budget of 4.
	var names []string
	for team := range 4 {
		var tunnels []agent.Tunnel
		for i, domain := range []string{tunnelDomain, tunnelDomain, second, second} {
			tun := shop(t)
			tun.Name, tun.Domain, tun.Subdomain = fmt.Sprintf("t%d", i), domain, fmt.Sprintf("team%d-n%d", team, i)
			names = append(names, tun.Subdomain+"."+domain)
			tunnels = append(tunnels, tun)
		}
		h.start(t, fmt.Sprintf("eh_team%d", team), tunnels...)
	}
	approved := 0
	for _, name := range names {
		if ask(t, internal, name) == http.StatusOK {
			approved++
		}
	}
	if approved != 6 {
		t.Errorf("%d of %d names approved across two tunnel domains, want the install's 6", approved, len(names))
	}
}
