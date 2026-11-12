package e2e

import (
	"io"
	"net/http"
	"net/netip"
	"strings"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// office is a team rule set: one network, "Office", with the given ranges.
func office(teamID int, def bool, cidrs ...string) map[string]any {
	t := map[string]any{
		"team_id":   teamID,
		"networks":  []map[string]any{{"name": "Office", "cidrs": cidrs}},
		"addresses": []map[string]any{},
	}
	if def {
		t["default"] = map[string]any{"networks": []string{"Office"}}
	}
	return t
}

// eventually retries until the status is want, for rules that arrive with the next sync.
func eventually(t *testing.T, h *harness, host string, want int) string {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for {
		status, body := h.get(t, host, "/")
		if status == want {
			return body
		}
		if time.Now().After(deadline) {
			t.Fatalf("%s answered %d, want %d: %s", host, status, want, body)
		}
		time.Sleep(25 * time.Millisecond)
	}
}

func TestTheTeamDefaultLimitsTunnelsToItsNetworksAndChangesApplyAtOnce(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_office", 7, "acme")
	cp.setTeams(office(7, true, "203.0.113.0/24"))
	h := cp.connect(t, "")
	a := h.start(t, "eh_office", upstream(t, "web", echoPath()))

	if !strings.Contains(a.tunnels[0].Access, "Office") {
		t.Errorf("the agent was told access %q", a.tunnels[0].Access)
	}
	body := eventually(t, h, a.host(), http.StatusForbidden)
	if !strings.Contains(body, "certain networks") || !strings.Contains(body, "127.0.0.1") {
		t.Errorf("denied body = %q", body)
	}

	// Add the visitor's address to the network: the running tunnel follows.
	cp.setTeams(office(7, true, "203.0.113.0/24", "127.0.0.1"))
	eventually(t, h, a.host(), http.StatusOK)
}

func TestAnAddressSavedAsOpenOverridesARestrictedDefault(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_hooks", 7, "acme")
	team := office(7, true, "203.0.113.0/24")
	team["addresses"] = []map[string]any{{"hostname": "hooks." + tunnelDomain, "anyone": true}}
	cp.setTeams(team)
	h := cp.connect(t, "")

	hooks := upstream(t, "hooks", echoPath())
	hooks.Subdomain = "hooks"
	open := h.start(t, "eh_hooks", hooks)
	eventually(t, h, open.host(), http.StatusOK)

	closed := h.start(t, "eh_hooks", upstream(t, "web", echoPath()))
	eventually(t, h, closed.host(), http.StatusForbidden)
}

func TestTheAgentCanLimitATunnelByAddressOrByNetworkName(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_cli", 7, "acme")
	cp.setTeams(office(7, false, "127.0.0.0/8"))
	h := cp.connect(t, "")

	elsewhere := upstream(t, "web", echoPath())
	elsewhere.Allow = []string{"203.0.113.0/24"}
	a := h.start(t, "eh_cli", elsewhere)
	if a.tunnels[0].Access != "only from 203.0.113.0/24 (this run)" {
		t.Errorf("access = %q", a.tunnels[0].Access)
	}
	eventually(t, h, a.host(), http.StatusForbidden)

	here := upstream(t, "web2", echoPath())
	here.Allow = []string{"office"} // names are not case sensitive
	b := h.start(t, "eh_cli", here)
	eventually(t, h, b.host(), http.StatusOK)
}

func TestAnUnknownNetworkNameRefusesTheTunnel(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_unknown", 7, "acme")
	h := cp.connect(t, "")
	tun := upstream(t, "web", echoPath())
	tun.Allow = []string{"Nowhere VPN"}
	a, _ := h.launch(t, "eh_unknown", tun)
	if err := a.wait(t); err == nil || !strings.Contains(err.Error(), `no network called "Nowhere VPN"`) {
		t.Fatalf("got %v", err)
	}
}

func TestTheDashboardAndTheAgentMustBothLetAVisitorIn(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_both", 7, "acme")
	cp.setTeams(office(7, true, "127.0.0.1"))
	h := cp.connect(t, "")
	tun := upstream(t, "web", echoPath())
	tun.Allow = []string{"203.0.113.0/24"}
	a := h.start(t, "eh_both", tun)
	if !strings.Contains(a.tunnels[0].Access, "Office") || !strings.Contains(a.tunnels[0].Access, "203.0.113.0/24") {
		t.Errorf("access = %q", a.tunnels[0].Access)
	}
	eventually(t, h, a.host(), http.StatusForbidden)
}

func TestANameAnotherTeamSavedIsRefused(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_mine", 7, "acme")
	cp.issue("eh_theirs", 8, "other")
	cp.reserve("shop."+tunnelDomain, 8)
	h := cp.connect(t, "")

	tun := upstream(t, "web", echoPath())
	tun.Subdomain = "shop"
	a, _ := h.launch(t, "eh_mine", tun)
	if err := a.wait(t); err == nil || !strings.Contains(err.Error(), "is saved by another team") {
		t.Fatalf("got %v", err)
	}
	theirs := h.start(t, "eh_theirs", tun)
	if theirs.host() != "shop."+tunnelDomain {
		t.Errorf("the team that saved it got %s", theirs.host())
	}
}

func TestTheVisitorIsTheRightMostAddressAfterTheTrustedProxy(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_xff", 7, "acme")
	h := cp.connect(t, "", func(c *server.Config) {
		c.TrustedProxies = []netip.Prefix{netip.MustParsePrefix("127.0.0.0/8")}
	})
	tun := upstream(t, "web", echoPath())
	tun.Allow = []string{"198.51.100.7"}
	a := h.start(t, "eh_xff", tun)

	status := func(xff string) int {
		req, _ := http.NewRequest("GET", "http://"+a.host()+"/", nil)
		req.Header.Set("X-Forwarded-For", xff)
		resp, err := h.visitor.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()
		return resp.StatusCode
	}
	// Our proxy appended the real visitor; what the client claimed is to the left.
	if got := status("203.0.113.1, 198.51.100.7"); got != http.StatusOK {
		t.Errorf("real visitor on the right: %d", got)
	}
	if got := status("198.51.100.7, 203.0.113.1"); got != http.StatusForbidden {
		t.Errorf("forged address on the left let in: %d", got)
	}
}

func TestOneAgentServesSeveralTunnelsWithTheirOwnRules(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_multi", 7, "acme")
	h := cp.connect(t, "")
	locked := upstream(t, "shop", echoPath())
	locked.Allow = []string{"203.0.113.0/24"}
	open := upstream(t, "api", echoPath())
	a := h.start(t, "eh_multi", locked, open)
	if len(a.tunnels) != 2 {
		t.Fatalf("got %d tunnels", len(a.tunnels))
	}
	eventually(t, h, a.tunnels[0].ID, http.StatusForbidden)
	eventually(t, h, a.tunnels[1].ID, http.StatusOK)
}

func TestNetworkNamesThatDifferOnlyInCaseAdmitNobody(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_case", 7, "acme")
	// Only the rule's own network, which the visitor is not in.
	cp.setTeams(map[string]any{
		"team_id":   7,
		"default":   map[string]any{"networks": []string{"VPN"}},
		"networks":  []map[string]any{{"name": "VPN", "cidrs": []string{"203.0.113.0/24"}}},
		"addresses": []map[string]any{},
	})
	h := cp.connect(t, "")
	a := h.start(t, "eh_case", upstream(t, "web", echoPath()))
	eventually(t, h, a.host(), http.StatusForbidden)

	// An older dashboard let a second network, "vpn", in, which does hold
	// the visitor. Whichever comes last, the rule must not admit through it.
	for _, rule := range []string{"VPN", "vpn"} {
		cp.setTeams(map[string]any{
			"team_id": 7,
			"default": map[string]any{"networks": []string{rule}},
			"networks": []map[string]any{
				{"name": "VPN", "cidrs": []string{"203.0.113.0/24"}},
				{"name": "vpn", "cidrs": []string{"127.0.0.0/8"}},
			},
			"addresses": []map[string]any{},
		})
		for range 40 { // two seconds of syncs
			if status, _ := h.get(t, a.host(), "/"); status != http.StatusForbidden {
				t.Fatalf("rule [%s]: visitor got %d, want 403 while two networks share the name", rule, status)
			}
			time.Sleep(50 * time.Millisecond)
		}
	}
}
