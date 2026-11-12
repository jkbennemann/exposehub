package server

import (
	"net/http"
	"strings"
	"sync"
	"time"
)

// InternalHandler serves the endpoints meant for the private network next to
// the server, never for visitors: a health check, and the question a reverse
// proxy with on-demand certificates asks before it obtains one.
//
//	GET /healthz             200 while the server runs
//	GET /tls/ask?domain=NAME 200 if the proxy may get a certificate for NAME, 403 if not
func (s *Server) InternalHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		_, _ = w.Write([]byte("ok\n"))
	})
	mux.HandleFunc("GET /tls/ask", func(w http.ResponseWriter, r *http.Request) {
		name := r.URL.Query().Get("domain")
		if name == "" {
			http.Error(w, "domain is required", http.StatusBadRequest)
			return
		}
		if !s.MayIssueCertificate(name, time.Now()) {
			http.Error(w, "not a hostname this server serves", http.StatusForbidden)
			return
		}
		w.WriteHeader(http.StatusOK)
	})
	return mux
}

// MayIssueCertificate reports whether a proxy in front of the server may
// obtain a certificate for name. Saying yes to arbitrary names would let
// anyone make the proxy request certificates for hostnames of their choice,
// so only these qualify: the agent host, a domain a team has verified, and,
// when the operator allows it, a name directly under a tunnel domain that a
// tunnel holds right now or holds for its grace period. Such names are
// budgeted, per account and for the whole install: names cost nothing to
// make up, certificates do.
func (s *Server) MayIssueCertificate(name string, now time.Time) bool {
	host := normalizeHost(name)
	if host == "" {
		return false
	}
	if host == s.cfg.AgentHost || s.isConnectedDomain(host) {
		return true
	}
	if !s.cfg.TLSAskTunnelNames {
		return false
	}
	for _, domain := range s.cfg.TunnelDomains {
		label, ok := strings.CutSuffix(host, "."+domain)
		if !ok || !validLabel(label) {
			continue
		}
		account, ok := s.reg.owner(host, now)
		if !ok {
			return false
		}
		switch refused, warn := s.certs.allow(account, allTunnelDomains, host, now); {
		case refused == budgetAccount && warn:
			s.log.Warn("certificates refused for new tunnel names of an account that used up its budget; EXPOSEHUB_TLS_ASK_NAME_LIMIT sets it",
				"host", host, "account", account, "limit", s.cfg.TLSAskNameLimit, "window", s.cfg.TLSAskNameWindow)
		case refused == budgetTotal && warn:
			s.log.Warn("certificates refused for new tunnel names: the install used up its budget; EXPOSEHUB_TLS_ASK_TOTAL_LIMIT sets it",
				"host", host, "limit", s.cfg.TLSAskTotalLimit, "window", s.cfg.TLSAskNameWindow)
		}
		return s.certs.approved(allTunnelDomains, host)
	}
	return false
}

// allTunnelDomains is the one key the install-wide budget is counted under.
// Let's Encrypt counts per registered domain, and several tunnel domains of
// one install can share one (tunnels.example.com and eu.tunnels.example.com),
// so a budget per tunnel domain would add up to more than the allowance.
const allTunnelDomains = "*"

// Which budget refused a name.
const (
	budgetNone = iota
	budgetAccount
	budgetTotal
)

// certBudget counts the tunnel names approved for a certificate within a
// sliding window, per account and for all tunnel domains together. Let's Encrypt limits
// new certificates per registered domain, and every account shares that
// allowance; an account's own limit stays below the total, so no single
// account can use all of it.
type certBudget struct {
	limit  int // per account
	total  int // all tunnel domains together; negative means no install-wide limit
	window time.Duration

	mu        sync.Mutex
	byAccount map[string]map[string]time.Time // account -> host -> first approval
	byDomain  map[string]map[string]time.Time // tunnel domain -> host -> first approval
	warned    map[string]time.Time            // budget key -> last warning
}

func newCertBudget(limit, total int, window time.Duration) *certBudget {
	return &certBudget{limit: limit, total: total, window: window,
		byAccount: map[string]map[string]time.Time{}, byDomain: map[string]map[string]time.Time{}, warned: map[string]time.Time{}}
}

// allow approves host, under domain, for account: free if it was approved
// within the window already, otherwise if both the account and the domain
// have budget left. It returns which budget refused it, if one did, and
// whether that is the first refusal of that budget in this window, which the
// caller logs.
func (b *certBudget) allow(account, domain, host string, now time.Time) (refused int, warn bool) {
	b.mu.Lock()
	defer b.mu.Unlock()
	mine, all := b.prune(b.byAccount, account, now), b.prune(b.byDomain, domain, now)
	if _, ok := all[host]; ok {
		return budgetNone, false // its certificate exists; asking again costs nothing
	}
	key := ""
	switch {
	case len(mine) >= b.limit:
		refused, key = budgetAccount, "account:"+account
	case b.total > 0 && len(all) >= b.total:
		refused, key = budgetTotal, "domain:"+domain
	}
	if refused != budgetNone {
		if last, ok := b.warned[key]; !ok || now.Sub(last) >= b.window {
			b.warned[key] = now
			warn = true
		}
		return refused, warn
	}
	if mine == nil {
		mine = map[string]time.Time{}
		b.byAccount[account] = mine
	}
	if all == nil {
		all = map[string]time.Time{}
		b.byDomain[domain] = all
	}
	mine[host], all[host] = now, now
	return budgetNone, false
}

// approved reports whether host holds an approval in domain's window.
func (b *certBudget) approved(domain, host string) bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	_, ok := b.byDomain[domain][host]
	return ok
}

// prune drops approvals of m[key] older than the window and returns what is left.
func (b *certBudget) prune(m map[string]map[string]time.Time, key string, now time.Time) map[string]time.Time {
	names := m[key]
	for h, at := range names {
		if now.Sub(at) >= b.window {
			delete(names, h)
		}
	}
	if names != nil && len(names) == 0 {
		delete(m, key)
		return nil
	}
	return names
}
