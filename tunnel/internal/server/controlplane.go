package server

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	snapshotPath = "/internal/v1/snapshot"
	// An unknown token triggers an early sync, so a token created a moment
	// ago works at once. This spaces those early syncs out, which bounds what
	// someone guessing tokens can make the control plane do. Regular polls do
	// not count: a token created just after one must not wait for the next.
	minRefreshGap = 2 * time.Second
	// defaultMaxStale is how long a snapshot is honoured without being
	// refreshed. Beyond it the server cannot know which tokens were revoked.
	defaultMaxStale = 5 * time.Minute
)

// errSnapshotStale is returned for a dashboard token while the snapshot that
// lists it is too old to vouch for it. The agent should retry, not give up.
var errSnapshotStale = errors.New("the tunnel server cannot confirm agent tokens with the dashboard right now")

// ControlPlaneConfig configures the link to the web application that issues
// agent tokens.
type ControlPlaneConfig struct {
	// URL is the base URL of the control plane, for example https://example.com.
	URL string
	// Secret is the shared secret the control plane expects as a bearer token.
	Secret string
	// CacheFile, if set, keeps the last snapshot on disk so that a restart
	// while the control plane is down still knows every token.
	CacheFile string
	// Interval is how often to poll for changes. Defaults to 10 seconds.
	Interval time.Duration
	// Client defaults to a client with a 10 second timeout.
	Client *http.Client
	// MaxStale is how long the snapshot is trusted when it cannot be
	// refreshed. After that, dashboard tokens are refused and their sessions
	// closed until a refresh succeeds, so a revocation cannot be held back
	// indefinitely by a dashboard that keeps failing. Zero means 5 minutes; a
	// negative value trusts the last snapshot forever.
	MaxStale time.Duration
}

// ControlPlane is an Authenticator that mirrors the control plane's token
// list. It answers from memory, so agents can connect and reconnect while
// the control plane is unreachable.
type ControlPlane struct {
	cfg ControlPlaneConfig
	log *slog.Logger

	mu      sync.RWMutex
	tokens  map[[sha256.Size]byte]Principal
	ids     map[string]bool
	domains map[string]string // custom hostname -> owning account
	// reserved are the subdomains teams saved: hostname -> account.
	reserved map[string]string
	// rules are the access rules per account.
	rules map[string]*teamRules
	// disconnects are the sessions the dashboard wants closed: session id ->
	// the id of the token it saw the session under.
	disconnects map[string]string
	etag        string
	// refreshed is when the dashboard last confirmed the snapshot, by sending
	// it or by answering that it has not changed.
	refreshed time.Time

	refreshMu sync.Mutex
	// lastEarly is when an unknown token last triggered a sync.
	lastEarly time.Time

	onChange func()
	onStale  func()
	// wasStale is only used by Run, to log the change once.
	wasStale bool
}

type snapshot struct {
	Tokens   []snapshotToken    `json:"tokens"`
	Domains  []snapshotDomain   `json:"domains"`
	Reserved []snapshotDomain   `json:"reserved"`
	Teams    []snapshotTeamRule `json:"teams"`
	// Disconnects lists the sessions somebody closed from the dashboard in
	// the last few minutes. The dashboard decides who may close which.
	Disconnects []snapshotDisconnect `json:"disconnects"`
}

// snapshotDisconnect asks for one session to be closed. Session is the id
// from the status report. TokenID is the token the dashboard saw it under;
// a session under another token is left alone.
type snapshotDisconnect struct {
	Session string `json:"session"`
	TokenID int64  `json:"token_id"`
}

// snapshotTeamRule is one team's access rules. A nil Default means anyone may
// reach the team's tunnels unless an address says otherwise.
type snapshotTeamRule struct {
	TeamID    int64             `json:"team_id"`
	Default   *snapshotAccess   `json:"default"`
	Networks  []snapshotNetwork `json:"networks"`
	Addresses []snapshotAddress `json:"addresses"`
}

// snapshotAccess limits requests to the named networks of the team.
type snapshotAccess struct {
	Networks []string `json:"networks"`
}

type snapshotNetwork struct {
	Name  string   `json:"name"`
	CIDRs []string `json:"cidrs"`
}

// snapshotAddress is the rule of one saved address or domain: Anyone, or
// only the networks in Access. Addresses that follow the team's default are
// not listed.
type snapshotAddress struct {
	Hostname string          `json:"hostname"`
	Anyone   bool            `json:"anyone"`
	Access   *snapshotAccess `json:"access"`
}

// teamRules is a team's rules, ready for lookups.
type teamRules struct {
	def      []string            // network names; nil = anyone
	hosts    map[string][]string // per host; nil entry = anyone
	networks map[string][]netip.Prefix
	names    map[string]string // lower-case name -> name as written
}

type snapshotDomain struct {
	Hostname string `json:"hostname"`
	TeamID   int64  `json:"team_id"`
}

type snapshotToken struct {
	ID     int64  `json:"id"`
	Hash   string `json:"hash"`
	TeamID int64  `json:"team_id"`
	Team   string `json:"team"`
}

// NewControlPlane returns a mirror primed from the cache file, if there is one.
func NewControlPlane(cfg ControlPlaneConfig, log *slog.Logger) (*ControlPlane, error) {
	cfg.URL = strings.TrimRight(cfg.URL, "/")
	if cfg.URL == "" || cfg.Secret == "" {
		return nil, errors.New("the control plane needs both a URL and a node secret")
	}
	if cfg.Interval <= 0 {
		cfg.Interval = 10 * time.Second
	}
	if cfg.Client == nil {
		cfg.Client = &http.Client{Timeout: 10 * time.Second}
	}
	if cfg.MaxStale == 0 {
		cfg.MaxStale = defaultMaxStale
	}
	c := &ControlPlane{cfg: cfg, log: log, tokens: map[[sha256.Size]byte]Principal{}, ids: map[string]bool{}, domains: map[string]string{},
		reserved: map[string]string{}, rules: map[string]*teamRules{}}
	if cfg.CacheFile != "" {
		switch body, err := os.ReadFile(cfg.CacheFile); {
		case err == nil:
			if err := c.apply(body); err != nil {
				log.Warn("ignoring an unreadable token cache", "file", cfg.CacheFile, "err", err)
			} else {
				// The file is as old as the dashboard's last confirmation: a
				// restart must not make an old snapshot count as current.
				if info, err := os.Stat(cfg.CacheFile); err == nil {
					c.refreshed = info.ModTime()
				}
				log.Info("loaded tokens from the cache", "count", len(c.tokens))
			}
		case !errors.Is(err, os.ErrNotExist):
			return nil, err
		}
	}
	return c, nil
}

// Attach makes revocations take effect on connected agents: after every
// change, sessions whose token is gone are closed. While the snapshot is too
// old to vouch for any token, every session of a dashboard token is closed;
// those agents reconnect once the dashboard answers again.
func (c *ControlPlane) Attach(s *Server) {
	c.onStale = func() {
		s.closeSessionsWhere(func(p Principal) bool {
			return p.issuer == Authenticator(c)
		}, errSnapshotStale.Error(), false)
	}
	c.onChange = func() {
		s.CloseSessionsWhere(func(p Principal) bool {
			return p.issuer == Authenticator(c) && !c.valid(p.TokenID)
		}, "the token was revoked")
		s.CloseSessionsServing(func(host string, p Principal) bool {
			account, ok := c.DomainOwner(host)
			return p.issuer == Authenticator(c) && (!ok || account != p.Account)
		}, "the domain was removed from the team")
		c.mu.RLock()
		disconnects := c.disconnects
		c.mu.RUnlock()
		for session, tokenID := range disconnects {
			s.CloseSession(session, func(p Principal) bool {
				return p.issuer == Authenticator(c) && p.TokenID == tokenID
			}, "it was disconnected in the dashboard")
		}
	}
}

// DomainOwner implements DomainAuthority.
func (c *ControlPlane) DomainOwner(hostname string) (string, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	account, ok := c.domains[hostname]
	return account, ok
}

// Run polls the control plane until ctx is cancelled.
func (c *ControlPlane) Run(ctx context.Context) {
	tick := time.NewTicker(c.cfg.Interval)
	defer tick.Stop()
	for {
		if err := c.refresh(ctx, false); err != nil && ctx.Err() == nil {
			c.log.Warn("could not reach the control plane; using the tokens already known", "err", err)
		}
		c.checkStale(time.Now())
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

// checkStale closes the dashboard's sessions while the snapshot is too old,
// and says so in the log when that starts and ends.
func (c *ControlPlane) checkStale(now time.Time) {
	stale := !c.current(now)
	switch {
	case stale && !c.wasStale:
		c.mu.RLock()
		refreshed := c.refreshed
		c.mu.RUnlock()
		c.log.Error("the dashboard's token list could not be refreshed for too long; refusing dashboard tokens and closing their tunnels until it can",
			"max_age", c.cfg.MaxStale, "last_refreshed", refreshed)
	case !stale && c.wasStale:
		c.log.Info("the dashboard's token list is current again; dashboard tokens are accepted again")
	}
	c.wasStale = stale
	if stale && c.onStale != nil {
		c.onStale()
	}
}

// current reports whether the snapshot is recent enough to vouch for tokens.
// Before the first snapshot there are no tokens, so there is nothing to vouch for.
func (c *ControlPlane) current(now time.Time) bool {
	if c.cfg.MaxStale < 0 {
		return true
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.refreshed.IsZero() || now.Sub(c.refreshed) <= c.cfg.MaxStale
}

// Authenticate implements Authenticator.
func (c *ControlPlane) Authenticate(ctx context.Context, token string) (Principal, error) {
	hash := sha256.Sum256([]byte(token))
	if p, ok := c.lookup(hash); ok && c.current(time.Now()) {
		return p, nil
	}
	// Possibly a token created since the last poll, or a snapshot that is
	// too old and may be refreshed now.
	if err := c.refresh(ctx, true); err != nil {
		c.log.Debug("early sync for an unknown token failed", "err", err)
	}
	if p, ok := c.lookup(hash); ok {
		if !c.current(time.Now()) {
			return Principal{}, errSnapshotStale
		}
		return p, nil
	}
	return Principal{}, ErrUnauthorized
}

// recheck implements sessionChecker. A session is checked again just before
// it starts serving, because the sweep after a snapshot change only sees
// sessions that are already serving.
func (c *ControlPlane) recheck(p Principal, custom []string) (reason string, final bool) {
	if !c.current(time.Now()) {
		return errSnapshotStale.Error(), false
	}
	if !c.valid(p.TokenID) {
		return "the token was revoked", true
	}
	for _, host := range custom {
		if account, ok := c.DomainOwner(host); !ok || account != p.Account {
			return "the domain was removed from the team", true
		}
	}
	return "", false
}

func (c *ControlPlane) lookup(hash [sha256.Size]byte) (Principal, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	p, ok := c.tokens[hash]
	return p, ok
}

func (c *ControlPlane) valid(tokenID string) bool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.ids[tokenID]
}

// refresh fetches the snapshot. An early refresh, triggered by an unknown
// token, is skipped when another one ran a moment ago.
func (c *ControlPlane) refresh(ctx context.Context, early bool) error {
	c.refreshMu.Lock()
	defer c.refreshMu.Unlock()
	if early {
		if time.Since(c.lastEarly) < minRefreshGap {
			return nil
		}
		c.lastEarly = time.Now()
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.cfg.URL+snapshotPath, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.cfg.Secret)
	req.Header.Set("Accept", "application/json")
	c.mu.RLock()
	etag := c.etag
	c.mu.RUnlock()
	if etag != "" {
		req.Header.Set("If-None-Match", etag)
	}

	resp, err := c.cfg.Client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	switch resp.StatusCode {
	case http.StatusNotModified:
		now := time.Now()
		c.mu.Lock()
		c.refreshed = now
		c.mu.Unlock()
		if c.cfg.CacheFile != "" {
			// The cache's age is read back as the snapshot's age after a restart.
			_ = os.Chtimes(c.cfg.CacheFile, now, now)
		}
		// Sweep anyway: a session that was still in its handshake during the
		// last change's sweep is caught here at the latest.
		if c.onChange != nil {
			c.onChange()
		}
		return nil
	case http.StatusOK:
	case http.StatusUnauthorized:
		return errors.New("the control plane rejected the node secret")
	default:
		return fmt.Errorf("the control plane answered %s", resp.Status)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		return err
	}
	if err := c.apply(body); err != nil {
		return err
	}
	c.mu.Lock()
	c.etag = resp.Header.Get("ETag")
	c.refreshed = time.Now()
	count := len(c.tokens)
	c.mu.Unlock()
	c.log.Info("synced tokens from the control plane", "count", count)

	if c.cfg.CacheFile != "" {
		if err := writeFileAtomic(c.cfg.CacheFile, body); err != nil {
			c.log.Warn("could not write the token cache", "file", c.cfg.CacheFile, "err", err)
		}
	}
	if c.onChange != nil {
		c.onChange()
	}
	return nil
}

// apply replaces the token list with the one in a snapshot body.
func (c *ControlPlane) apply(body []byte) error {
	var snap snapshot
	if err := json.Unmarshal(body, &snap); err != nil {
		return fmt.Errorf("reading the snapshot: %w", err)
	}
	tokens := make(map[[sha256.Size]byte]Principal, len(snap.Tokens))
	ids := make(map[string]bool, len(snap.Tokens))
	for _, t := range snap.Tokens {
		raw, err := hex.DecodeString(t.Hash)
		if err != nil || len(raw) != sha256.Size {
			return fmt.Errorf("token %d has a malformed hash", t.ID)
		}
		id := strconv.FormatInt(t.ID, 10)
		tokens[[sha256.Size]byte(raw)] = Principal{
			Account: teamAccount(t.TeamID),
			TokenID: id,
			Label:   t.Team,
			issuer:  c,
		}
		ids[id] = true
	}
	domains := make(map[string]string, len(snap.Domains))
	for _, d := range snap.Domains {
		domains[normalizeHost(d.Hostname)] = teamAccount(d.TeamID)
	}
	reserved := make(map[string]string, len(snap.Reserved))
	for _, d := range snap.Reserved {
		reserved[normalizeHost(d.Hostname)] = teamAccount(d.TeamID)
	}
	rules := make(map[string]*teamRules, len(snap.Teams))
	for _, t := range snap.Teams {
		tr := &teamRules{hosts: map[string][]string{}, networks: map[string][]netip.Prefix{}, names: map[string]string{}}
		for _, n := range t.Networks {
			key := strings.ToLower(strings.TrimSpace(n.Name))
			if _, dup := tr.names[key]; dup {
				// Rules name networks ignoring case, so two networks whose
				// names differ only in case cannot be told apart. Letting
				// one win would admit the other's ranges; admit nobody.
				c.log.Warn("a team has two networks whose names differ only in case; rules naming them admit nobody", "account", teamAccount(t.TeamID), "network", n.Name)
				tr.networks[key] = []netip.Prefix{}
				continue
			}
			prefixes := make([]netip.Prefix, 0, len(n.CIDRs))
			for _, cidr := range n.CIDRs {
				// The dashboard validates; an entry that still does not parse
				// is left out, which narrows the network, never widens it.
				if p, ok := parseAllowPrefix(cidr); ok {
					prefixes = append(prefixes, p)
				}
			}
			tr.networks[key] = prefixes
			tr.names[key] = n.Name
		}
		if t.Default != nil {
			tr.def = append([]string{}, t.Default.Networks...)
		}
		for _, a := range t.Addresses {
			switch {
			case a.Anyone:
				tr.hosts[normalizeHost(a.Hostname)] = nil
			case a.Access != nil:
				tr.hosts[normalizeHost(a.Hostname)] = append([]string{}, a.Access.Networks...)
			}
		}
		rules[teamAccount(t.TeamID)] = tr
	}
	disconnects := make(map[string]string, len(snap.Disconnects))
	for _, d := range snap.Disconnects {
		disconnects[d.Session] = strconv.FormatInt(d.TokenID, 10)
	}
	c.mu.Lock()
	c.tokens, c.ids, c.domains, c.reserved, c.rules, c.disconnects = tokens, ids, domains, reserved, rules, disconnects
	c.mu.Unlock()
	return nil
}

// Reservation implements ReservationAuthority.
func (c *ControlPlane) Reservation(hostname string) (string, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	account, ok := c.reserved[hostname]
	return account, ok
}

// ruleFor returns the network names host is limited to, and whether it is limited at all.
func (c *ControlPlane) ruleFor(account, host string) ([]string, *teamRules, bool) {
	tr := c.rules[account]
	if tr == nil {
		return nil, nil, false
	}
	if names, ok := tr.hosts[host]; ok {
		return names, tr, names != nil
	}
	return tr.def, tr, tr.def != nil
}

// AccessFor implements AccessAuthority.
func (c *ControlPlane) AccessFor(account, host string) ([]netip.Prefix, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	names, tr, restricted := c.ruleFor(account, host)
	if !restricted {
		return nil, false
	}
	allowed := []netip.Prefix{}
	for _, name := range names {
		allowed = append(allowed, tr.networks[strings.ToLower(name)]...)
	}
	return allowed, true
}

// Network implements AccessAuthority.
func (c *ControlPlane) Network(account, name string) ([]netip.Prefix, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	tr := c.rules[account]
	if tr == nil {
		return nil, false
	}
	prefixes, ok := tr.networks[strings.ToLower(strings.TrimSpace(name))]
	return prefixes, ok
}

// DescribeAccess implements AccessAuthority.
func (c *ControlPlane) DescribeAccess(account, host string) string {
	c.mu.RLock()
	defer c.mu.RUnlock()
	names, tr, restricted := c.ruleFor(account, host)
	if !restricted {
		return ""
	}
	if len(names) == 0 {
		return "nobody, until networks are chosen"
	}
	shown := make([]string, 0, len(names))
	for _, n := range names {
		if written, ok := tr.names[strings.ToLower(n)]; ok {
			n = written
		}
		shown = append(shown, n)
	}
	return "only from " + strings.Join(shown, ", ")
}

// teamAccount is the account name of a team, shared by its tokens and domains.
func teamAccount(teamID int64) string {
	return "team:" + strconv.FormatInt(teamID, 10)
}

// writeFileAtomic replaces path in one step, so a crash never leaves half a file.
func writeFileAtomic(path string, data []byte) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), ".snapshot-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}
