package server

import (
	"testing"
	"time"
)

func TestTheCertificateBudgetsRefillAfterTheirWindow(t *testing.T) {
	b := newCertBudget(2, 3, time.Hour)
	start := time.Now()
	for _, c := range []struct {
		account, host string
		at            time.Duration
		want          int
	}{
		{"team:1", "a.t.test", 0, budgetNone},
		{"team:1", "b.t.test", time.Minute, budgetNone},
		{"team:1", "c.t.test", 2 * time.Minute, budgetAccount},
		{"team:1", "a.t.test", 3 * time.Minute, budgetNone}, // approved already
		{"team:2", "d.t.test", 3 * time.Minute, budgetNone},
		{"team:3", "e.t.test", 4 * time.Minute, budgetTotal}, // the domain's three are used
		{"team:2", "b.t.test", 5 * time.Minute, budgetNone},  // approved already, for anyone
		{"team:1", "c.t.test", time.Hour, budgetNone},        // a.t.test has left the window
		{"team:3", "e.t.test", time.Hour + 2*time.Minute, budgetNone},
		{"team:3", "f.t.test", time.Hour + 2*time.Minute, budgetTotal},
	} {
		if got, _ := b.allow(c.account, "t.test", c.host, start.Add(c.at)); got != c.want {
			t.Errorf("allow(%s, %s) at +%v refused by %d, want %d", c.account, c.host, c.at, got, c.want)
		}
	}
}

func TestACertificateBudgetWarnsOncePerWindow(t *testing.T) {
	b := newCertBudget(1, 5, time.Hour)
	start := time.Now()
	b.allow("team:1", "t.test", "a.t.test", start)
	warnings := 0
	for i, at := range []time.Duration{time.Minute, 2 * time.Minute, 30 * time.Minute, time.Hour + 2*time.Minute, time.Hour + 3*time.Minute} {
		if _, warn := b.allow("team:1", "t.test", "x"+string(rune('a'+i))+".t.test", start.Add(at)); warn {
			warnings++
		}
	}
	// One in the first hour. At +1h2m a name is approved, a.t.test having
	// left the window; the refusal after it starts a new window's warning.
	if warnings != 2 {
		t.Errorf("%d warnings, want 2", warnings)
	}
}

func TestWithoutAnInstallWideLimitOnlyAccountsAreBudgeted(t *testing.T) {
	b := newCertBudget(2, -1, time.Hour)
	now := time.Now()
	for i := range 20 {
		account := "team:" + string(rune('a'+i))
		if got, _ := b.allow(account, "t.test", account+".t.test", now); got != budgetNone {
			t.Fatalf("account %d refused by %d", i, got)
		}
	}
}
