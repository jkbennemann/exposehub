package server

import (
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/netip"
	"strings"

	"github.com/jkbennemann/exposehub/tunnel/internal/errorpage"
)

// Limits on what an agent may ask for, so a hello stays small.
const (
	maxAllowEntries = 64
	maxNetworkName  = 100
)

// AccessAuthority keeps access rules for its accounts' tunnels: which
// networks a request must come from. The dashboard is one; static tokens are not.
type AccessAuthority interface {
	// AccessFor returns the networks a request to host, served by account,
	// must come from. restricted false means anyone may reach it.
	AccessFor(account, host string) (allowed []netip.Prefix, restricted bool)
	// Network returns one of account's named networks.
	Network(account, name string) ([]netip.Prefix, bool)
	// DescribeAccess names the rule for host in words, or "" for anyone.
	DescribeAccess(account, host string) string
}

// ReservationAuthority knows which subdomains an account saved for itself.
// Nobody else may open a tunnel on such a name.
type ReservationAuthority interface {
	Reservation(hostname string) (account string, ok bool)
}

// Reservation implements ReservationAuthority by asking the members that are one.
func (as Authenticators) Reservation(hostname string) (string, bool) {
	for _, a := range as {
		if r, ok := a.(ReservationAuthority); ok {
			if account, ok := r.Reservation(hostname); ok {
				return account, true
			}
		}
	}
	return "", false
}

// agentAllow is what an agent asked for on top of the dashboard's rule, for
// one host: addresses and networks, and names of its team's networks.
type agentAllow struct {
	prefixes []netip.Prefix
	networks []string
}

// parseAllow reads the --allow values of one tunnel. Each is an address, a
// network in CIDR notation, or the name of one of the team's networks.
func parseAllow(values []string, who Principal) (*agentAllow, error) {
	if len(values) == 0 {
		return nil, nil
	}
	if len(values) > maxAllowEntries {
		return nil, fmt.Errorf("a tunnel may allow at most %d addresses and networks", maxAllowEntries)
	}
	allow := &agentAllow{}
	for _, v := range values {
		v = strings.TrimSpace(v)
		if p, ok := parseAllowPrefix(v); ok {
			allow.prefixes = append(allow.prefixes, p)
			continue
		}
		if v == "" || len(v) > maxNetworkName {
			return nil, fmt.Errorf("%q is neither an address, a network like 203.0.113.0/24 nor a network name", v)
		}
		authority, ok := who.issuer.(AccessAuthority)
		if !ok {
			return nil, fmt.Errorf("%q is not an address or a network like 203.0.113.0/24; named networks need a dashboard", v)
		}
		if _, ok := authority.Network(who.Account, v); !ok {
			return nil, fmt.Errorf("your team has no network called %q; add it in the dashboard under Access, or give addresses", v)
		}
		allow.networks = append(allow.networks, v)
	}
	return allow, nil
}

// parseAllowPrefix accepts "203.0.113.7", "2001:db8::1", "203.0.113.0/24"
// and "2001:db8::/48", and returns the network with the host bits cleared.
// Visitor addresses are compared unmapped, so IPv4-mapped IPv6 is unmapped too.
func parseAllowPrefix(v string) (netip.Prefix, bool) {
	if p, err := netip.ParsePrefix(v); err == nil {
		if p.Addr().Is4In6() {
			if p.Bits() < 96 {
				return netip.Prefix{}, false
			}
			p = netip.PrefixFrom(p.Addr().Unmap(), p.Bits()-96)
		}
		return p.Masked(), true
	}
	if a, err := netip.ParseAddr(v); err == nil && a.Zone() == "" {
		a = a.Unmap()
		return netip.PrefixFrom(a, a.BitLen()), true
	}
	return netip.Prefix{}, false
}

// String describes the agent's own rule.
func (a *agentAllow) String() string {
	parts := make([]string, 0, len(a.prefixes)+len(a.networks))
	parts = append(parts, a.networks...)
	for _, p := range a.prefixes {
		parts = append(parts, p.String())
	}
	return strings.Join(parts, ", ")
}

// visitorAddr is the visitor's address. Behind a trusted reverse proxy it is
// the right-most address in X-Forwarded-For that is not a trusted proxy
// itself: entries further left came from the client and can say anything.
func (s *Server) visitorAddr(r *http.Request) netip.Addr {
	peer := addrOf(r.RemoteAddr)
	if !s.cfg.trusts(r.RemoteAddr) {
		return peer
	}
	var hops []string
	for _, v := range r.Header.Values("X-Forwarded-For") {
		hops = append(hops, strings.Split(v, ",")...)
	}
	for i := len(hops) - 1; i >= 0; i-- {
		a, err := netip.ParseAddr(strings.TrimSpace(hops[i]))
		if err != nil {
			// A malformed entry: everything to its left is unreliable.
			break
		}
		a = a.Unmap()
		if !s.cfg.trustsAddr(a) {
			return a
		}
	}
	return peer
}

func addrOf(hostport string) netip.Addr {
	host, _, err := net.SplitHostPort(hostport)
	if err != nil {
		host = hostport
	}
	a, _ := netip.ParseAddr(host)
	return a.Unmap()
}

// reachable reports whether the visitor of r may use the tunnel at host. A
// request has to pass the dashboard's rule and the agent's own, if either
// restricts; one that does not gets 403 and never reaches the agent.
func (s *Server) reachable(sess *session, host string, w http.ResponseWriter, r *http.Request) bool {
	var rules [][]netip.Prefix
	if authority, ok := sess.who.issuer.(AccessAuthority); ok {
		if allowed, restricted := authority.AccessFor(sess.who.Account, host); restricted {
			rules = append(rules, allowed)
		}
	}
	if own := sess.allow[host]; own != nil {
		allowed := append([]netip.Prefix(nil), own.prefixes...)
		if authority, ok := sess.who.issuer.(AccessAuthority); ok {
			// Resolved per request, so a network changed in the dashboard
			// applies at once; a network deleted since lets nobody in.
			for _, name := range own.networks {
				prefixes, _ := authority.Network(sess.who.Account, name)
				allowed = append(allowed, prefixes...)
			}
		}
		rules = append(rules, allowed)
	}
	if len(rules) == 0 {
		return true
	}
	addr := s.visitorAddr(r)
	for _, allowed := range rules {
		if !containsAddr(allowed, addr) {
			errorpage.Write(w, r, errorpage.NetworkDenied(addr.String()))
			return false
		}
	}
	return true
}

func containsAddr(prefixes []netip.Prefix, a netip.Addr) bool {
	if !a.IsValid() {
		return false
	}
	for _, p := range prefixes {
		if p.Contains(a) {
			return true
		}
	}
	return false
}

// describeAccess says in words who may reach host on sess, for the agent's
// output. Empty means anyone.
func (s *Server) describeAccess(sess *session, host string) string {
	var parts []string
	if authority, ok := sess.who.issuer.(AccessAuthority); ok {
		if d := authority.DescribeAccess(sess.who.Account, host); d != "" {
			parts = append(parts, d+" (dashboard)")
		}
	}
	if own := sess.allow[host]; own != nil {
		parts = append(parts, "only from "+own.String()+" (this run)")
	}
	// Both have to let a visitor in, so "and" is meant literally.
	return strings.Join(parts, " and ")
}

var errHostSaved = errors.New("is saved by another team")
