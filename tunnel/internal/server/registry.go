package server

import (
	"crypto/rand"
	"crypto/sha256"
	"errors"
	"fmt"
	"sync"
	"time"
)

var (
	errHostTaken    = errors.New("is in use by another account")
	errHostBusy     = errors.New("is in use by another agent of your team")
	errHostReserved = errors.New("is reserved")
)

// hostReq is one requested hostname: either a label under a tunnel domain,
// where an empty sub asks for a random one, or host, a domain of the
// account's own that the caller has already checked it may use.
type hostReq struct {
	domain string
	sub    string
	host   string
	// auth are the credentials visitors must present, as SHA-256 of "user:password".
	auth [][sha256.Size]byte
	// log is what the agent asked to be logged: LogOff, LogRequests or LogBodies.
	log string
	// allow is the agent's own limit on who may reach the tunnel, or nil.
	allow *agentAllow
}

// entry records who holds a hostname: the owning account and the token of
// the agent serving it. sess is nil while that agent is disconnected; the
// name then stays reserved for the account until releaseAt.
type entry struct {
	owner     string
	holder    string
	sess      *session
	releaseAt time.Time
}

func (e *entry) expired(now time.Time) bool {
	return e.sess == nil && now.After(e.releaseAt)
}

// registry maps public hostnames to the agent session serving them.
type registry struct {
	grace    time.Duration
	reserved map[string]bool

	mu    sync.RWMutex
	hosts map[string]*entry
}

func newRegistry(grace time.Duration, reserved ...string) *registry {
	r := &registry{grace: grace, reserved: map[string]bool{}, hosts: map[string]*entry{}}
	for _, h := range reserved {
		r.reserved[h] = true
	}
	return r
}

// claim assigns every requested hostname to sess, or none of them. A name
// that an agent with the same token is serving is taken over, which is what a
// reconnect after a network drop looks like; the sessions that lose a name
// are returned so the caller can close them. A name a teammate is serving is
// refused, and one whose agent is away can be picked up by anyone on the team.
func (r *registry) claim(who Principal, sess *session, reqs []hostReq, now time.Time) (hosts []string, displaced []*session, err error) {
	r.mu.Lock()
	defer r.mu.Unlock()

	seen := map[string]bool{}
	for _, q := range reqs {
		var host string
		if q.host == "" && q.sub == "" {
			if host, err = r.randomHostLocked(q.domain, seen, now); err != nil {
				return nil, nil, err
			}
		} else {
			host = q.host
			if host == "" {
				host = q.sub + "." + q.domain
			}
			switch e := r.hosts[host]; {
			case r.reserved[host]:
				return nil, nil, fmt.Errorf("%s %w", host, errHostReserved)
			case seen[host]:
				return nil, nil, fmt.Errorf("%s was requested twice", host)
			case e != nil && e.owner != who.Account && !e.expired(now):
				return nil, nil, fmt.Errorf("%s %w", host, errHostTaken)
			case e != nil && e.sess != nil && e.holder != who.TokenID:
				return nil, nil, fmt.Errorf("%s %w", host, errHostBusy)
			}
		}
		seen[host] = true
		hosts = append(hosts, host)
	}

	for _, host := range hosts {
		if e := r.hosts[host]; e != nil && e.sess != nil {
			displaced = append(displaced, e.sess)
		}
		r.hosts[host] = &entry{owner: who.Account, holder: who.TokenID, sess: sess}
	}
	return hosts, displaced, nil
}

// release marks the hostnames sess still holds as disconnected and starts
// their grace period.
func (r *registry) release(sess *session, now time.Time) {
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, host := range sess.hosts {
		if e := r.hosts[host]; e != nil && e.sess == sess {
			e.sess = nil
			e.releaseAt = now.Add(r.grace)
		}
	}
}

// lookup returns the session serving host. known is true when the hostname
// belongs to a tunnel whose agent is currently away.
func (r *registry) lookup(host string, now time.Time) (sess *session, known bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()
	e := r.hosts[host]
	if e == nil || e.expired(now) {
		return nil, false
	}
	return e.sess, true
}

// owner returns the account that holds host, connected or in its grace period.
func (r *registry) owner(host string, now time.Time) (string, bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()
	e := r.hosts[host]
	if e == nil || e.expired(now) {
		return "", false
	}
	return e.owner, true
}

// sweep drops reservations whose grace period has passed.
func (r *registry) sweep(now time.Time) {
	r.mu.Lock()
	defer r.mu.Unlock()
	for host, e := range r.hosts {
		if e.expired(now) {
			delete(r.hosts, host)
		}
	}
}

func (r *registry) randomHostLocked(domain string, seen map[string]bool, now time.Time) (string, error) {
	for range 10 {
		host := randomLabel() + "." + domain
		e := r.hosts[host]
		if !seen[host] && !r.reserved[host] && (e == nil || e.expired(now)) {
			return host, nil
		}
	}
	return "", errors.New("could not find a free random subdomain")
}

// randomLabel returns 10 characters carrying 50 bits of randomness.
func randomLabel() string {
	const alphabet = "abcdefghijklmnopqrstuvwxyz234567"
	var b [10]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err) // crypto/rand does not fail on supported platforms
	}
	for i := range b {
		b[i] = alphabet[b[i]&31]
	}
	return string(b[:])
}
