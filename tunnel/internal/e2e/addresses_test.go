package e2e

import (
	"context"
	"net/http"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
)

// addresses asks the server which addresses token may use, as the agent's
// MCP tool list_addresses does.
func (h *harness) addresses(t *testing.T, token string) (proto.Addresses, error) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return agent.FetchAddresses(ctx, &http.Client{Transport: pinned(h.addr, nil)}, "http://"+agentHost, token)
}

func TestAnAgentLearnsTheDomainsOfItsOwnTeamOnly(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_acme", 7, "acme")
	cp.issue("eh_other", 8, "other")
	cp.issueDemo("eh_demo")
	cp.connectDomain("Dev.Acme.Example", 7)
	cp.connectDomain("docs.acme.example", 7)
	cp.connectDomain("dev.other.example", 8)
	h := cp.connect(t, "")

	var got proto.Addresses
	deadline := time.Now().Add(5 * time.Second)
	for {
		var err error
		if got, err = h.addresses(t, "eh_acme"); err == nil && len(got.OwnDomains) == 2 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("acme's addresses: %+v, %v", got, err)
		}
		time.Sleep(20 * time.Millisecond)
	}
	if !slices.Equal(got.OwnDomains, []string{"dev.acme.example", "docs.acme.example"}) || !slices.Equal(got.TunnelDomains, []string{tunnelDomain}) {
		t.Errorf("acme's addresses = %+v", got)
	}

	if other, err := h.addresses(t, "eh_other"); err != nil || !slices.Equal(other.OwnDomains, []string{"dev.other.example"}) {
		t.Errorf("other's addresses = %+v, %v", other, err)
	}
	// Demo tunnels take random names only, so a demo token learns of no domain.
	if demo, err := h.addresses(t, "eh_demo"); err != nil || len(demo.OwnDomains) != 0 || demo.OwnDomains == nil {
		t.Errorf("demo's addresses = %+v, %v; want an empty list", demo, err)
	}
	if _, err := h.addresses(t, "eh_nobody"); err == nil || !strings.Contains(err.Error(), "refused the agent token") {
		t.Errorf("an unknown token got %v, want a refusal", err)
	}

	// Only GET, and nothing is cached on the way.
	req, _ := http.NewRequest(http.MethodPost, "http://"+agentHost+proto.AddressesPath, nil)
	req.Header.Set("Authorization", "Bearer eh_acme")
	resp, err := (&http.Client{Transport: pinned(h.addr, nil)}).Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Errorf("POST answered %d, want 405", resp.StatusCode)
	}
	req, _ = http.NewRequest(http.MethodGet, "http://"+agentHost+proto.AddressesPath, nil)
	req.Header.Set("Authorization", "Bearer eh_acme")
	if resp, err = (&http.Client{Transport: pinned(h.addr, nil)}).Do(req); err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if got := resp.Header.Get("Cache-Control"); got != "no-store" {
		t.Errorf("Cache-Control = %q, want no-store", got)
	}
}

func TestStaticTokensHaveNoDomainsToList(t *testing.T) {
	h := newHarness(t)
	got, err := h.addresses(t, "alice-token")
	if err != nil {
		t.Fatal(err)
	}
	if len(got.OwnDomains) != 0 || !slices.Equal(got.TunnelDomains, []string{tunnelDomain}) {
		t.Errorf("addresses = %+v", got)
	}
}
