// Package server implements the public tunnel server: it accepts agents on a
// WebSocket endpoint and forwards visitor requests to them by hostname.
package server

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"net/http/httputil"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/coder/websocket"
	"github.com/hashicorp/yamux"

	"github.com/jkbennemann/exposehub/tunnel/internal/errorpage"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/transport"
)

const (
	handshakeTimeout        = 10 * time.Second
	maxTunnelsPerSession    = 10
	maxCredentialsPerTunnel = 10
	// maxAgentVersion bounds the version an agent reports, which goes into
	// the status report; the dashboard accepts no more than 64 characters.
	maxAgentVersion = 64
	// maxSessionsPerToken bounds the sessions one token may hold at once,
	// counting those still in their handshake, so one token cannot tie up
	// the server's memory with sessions.
	maxSessionsPerToken = 32
)

// Server routes visitor traffic to connected agents. It is an http.Handler,
// so it can be mounted on any listener; Run serves it on the configured one.
type Server struct {
	cfg     Config
	auth    Authenticator
	log     *slog.Logger
	reg     *registry
	agents  *AgentRelease
	shipper *LogShipper
	certs   *certBudget

	mu       sync.Mutex
	sessions map[*session]struct{}
	// perToken counts the sessions of each token, from authentication on.
	perToken map[string]int
	// allowances holds what each demo token may still use, by token id.
	allowances map[string]*allowance
	// demoKey signs the proof that a browser has seen a demo tunnel's
	// notice. It lives as long as the process: after a restart browsers see
	// the notice once more.
	demoKey [32]byte
}

// New validates cfg and returns a server that is ready to serve.
func New(cfg Config, auth Authenticator, log *slog.Logger) (*Server, error) {
	if err := cfg.normalize(); err != nil {
		return nil, err
	}
	var agents *AgentRelease
	if cfg.AgentDownloads != "" {
		var err error
		if agents, err = LoadAgentRelease(cfg.AgentDownloads); err != nil {
			return nil, fmt.Errorf("agent downloads: %w", err)
		}
	}
	s := &Server{
		agents:     agents,
		cfg:        cfg,
		auth:       auth,
		log:        log,
		reg:        newRegistry(cfg.GracePeriod, cfg.AgentHost),
		certs:      newCertBudget(cfg.TLSAskNameLimit, cfg.TLSAskTotalLimit, cfg.TLSAskNameWindow),
		sessions:   map[*session]struct{}{},
		perToken:   map[string]int{},
		allowances: map[string]*allowance{},
	}
	s.reg.demoMax = cfg.demoTunnels()
	if _, err := rand.Read(s.demoKey[:]); err != nil {
		return nil, fmt.Errorf("making the key for demo notices: %w", err)
	}
	return s, nil
}

// Run serves until ctx is cancelled, then closes every tunnel and returns.
func (s *Server) Run(ctx context.Context) error {
	srv := &http.Server{
		Addr:    s.cfg.Listen,
		Handler: s,
		// No overall read or write timeout: tunnelled requests may stream for hours.
		ReadHeaderTimeout: 10 * time.Second,
		IdleTimeout:       2 * time.Minute,
		ErrorLog:          slog.NewLogLogger(s.log.Handler(), slog.LevelDebug),
	}

	go func() {
		tick := time.NewTicker(30 * time.Second)
		defer tick.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case now := <-tick.C:
				s.reg.sweep(now)
				s.sweepAllowances(now)
			}
		}
	}()

	errc := make(chan error, 2)
	go func() {
		if s.cfg.TLSCertFile != "" {
			errc <- srv.ListenAndServeTLS(s.cfg.TLSCertFile, s.cfg.TLSKeyFile)
		} else {
			errc <- srv.ListenAndServe()
		}
	}()
	var internal *http.Server
	if s.cfg.InternalListen != "" {
		internal = &http.Server{
			Addr:              s.cfg.InternalListen,
			Handler:           s.InternalHandler(),
			ReadHeaderTimeout: 5 * time.Second,
			ReadTimeout:       10 * time.Second,
			WriteTimeout:      10 * time.Second,
			ErrorLog:          slog.NewLogLogger(s.log.Handler(), slog.LevelDebug),
		}
		go func() {
			if err := internal.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
				errc <- fmt.Errorf("internal listener: %w", err)
			}
		}()
		s.log.Info("internal endpoints listening", "addr", s.cfg.InternalListen, "tls_ask_tunnel_names", s.cfg.TLSAskTunnelNames)
	}
	s.log.Info("tunnel server listening", "addr", s.cfg.Listen, "tls", s.cfg.TLSCertFile != "",
		"agent_host", s.cfg.AgentHost, "tunnel_domains", s.cfg.TunnelDomains, "agent_downloads", s.agentVersion())

	select {
	case err := <-errc:
		return err
	case <-ctx.Done():
	}
	s.Close()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if internal != nil {
		_ = internal.Shutdown(shutdownCtx)
	}
	return srv.Shutdown(shutdownCtx)
}

// Close disconnects every agent. Agents reconnect on their own.
func (s *Server) Close() {
	s.mu.Lock()
	sessions := make([]*session, 0, len(s.sessions))
	for sess := range s.sessions {
		sessions = append(sessions, sess)
	}
	s.mu.Unlock()
	for _, sess := range sessions {
		sess.close()
	}
}

func (s *Server) agentVersion() string {
	if s.agents == nil {
		return "none"
	}
	return s.agents.Version
}

// CloseSessionsWhere ends every session whose agent matches, telling it why
// and not to reconnect. It is how a revoked token takes effect on agents that
// are already connected.
func (s *Server) CloseSessionsWhere(match func(Principal) bool, reason string) {
	s.closeSessionsWhere(match, reason, true)
}

// closeSessionsWhere ends every session whose agent matches. final tells the
// agent not to reconnect.
func (s *Server) closeSessionsWhere(match func(Principal) bool, reason string, final bool) {
	s.mu.Lock()
	var matched []*session
	for sess := range s.sessions {
		if match(sess.who) {
			matched = append(matched, sess)
		}
	}
	s.mu.Unlock()
	for _, sess := range matched {
		sess.goodbye(reason, final)
	}
}

// ServeHTTP dispatches by hostname: the agent host serves the agent endpoint,
// every other host is looked up as a tunnel.
func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	host := normalizeHost(r.Host)
	if host == s.cfg.AgentHost {
		s.serveAgentHost(w, r)
		return
	}
	if len(s.cfg.CustomDomainHeaders) > 0 && s.isConnectedDomain(host) {
		for name, value := range s.cfg.CustomDomainHeaders {
			w.Header().Set(name, value)
		}
	}
	sess, known := s.reg.lookup(host, time.Now())
	switch {
	case sess != nil && !sess.ready.Load():
		// The agent has its addresses but its session is still being set up
		// (passwords, logging). Until then nothing may pass, protected or not.
		errorpage.Write(w, r, errorpage.Reconnecting())
	case sess != nil:
		// Recorded from here, so a visitor turned away by the tunnel's
		// password shows up in the owner's log too.
		rec := s.startLog(sess, host, r)
		if rec != nil {
			w = rec.wrap(w, r)
		}
		if s.admit(sess, host, w, r) {
			if sess.who.Demo {
				w = s.meter(sess, w, r)
			}
			sess.proxy.ServeHTTP(w, r)
		}
		if rec != nil {
			rec.finish(s)
		}
	case known:
		errorpage.Write(w, r, errorpage.Reconnecting())
	default:
		errorpage.Write(w, r, errorpage.NoTunnel(s.reopenCommand(host)))
	}
}

// reopenCommand is what the owner of host would type to open its tunnel
// again, shown on the page for an address nothing is connected at. Hosts
// that are not a single label under a tunnel domain are domains of a team.
func (s *Server) reopenCommand(host string) string {
	for i, domain := range s.cfg.TunnelDomains {
		name, ok := strings.CutSuffix(host, "."+domain)
		if !ok || name == "" || strings.Contains(name, ".") {
			continue
		}
		if i == 0 {
			return "exposehub http <port> --subdomain " + name
		}
		return "exposehub http <port> --subdomain " + name + " --domain " + domain
	}
	return "exposehub http <port> --hostname " + host
}

func (s *Server) serveAgentHost(w http.ResponseWriter, r *http.Request) {
	if s.serveDownloads(w, r) {
		return
	}
	switch r.URL.Path {
	case proto.AgentPath:
		s.serveAgent(w, r)
	case "/healthz":
		fmt.Fprintln(w, "ok")
	default:
		http.NotFound(w, r)
	}
}

// serveAgent runs one agent session. It returns when the session ends.
func (s *Server) serveAgent(w http.ResponseWriter, r *http.Request) {
	token, _ := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer ")
	principal, err := s.auth.Authenticate(r.Context(), token)
	if err != nil {
		if !errors.Is(err, ErrUnauthorized) {
			// The token may be fine but cannot be checked now: the agent
			// retries a 503, where a 401 would make it give up.
			w.Header().Set("Retry-After", "30")
			http.Error(w, "exposehub: "+err.Error(), http.StatusServiceUnavailable)
			return
		}
		w.Header().Set("WWW-Authenticate", "Bearer")
		http.Error(w, "exposehub: invalid agent token", http.StatusUnauthorized)
		return
	}
	sessionLimit := maxSessionsPerToken
	if principal.Demo {
		sessionLimit = maxSessionsPerDemoToken
	}
	if !s.holdSession(principal.TokenID, sessionLimit) {
		// The agent retries this later, by when sessions of a crashed or
		// reconnecting agent have ended.
		w.Header().Set("Retry-After", "30")
		http.Error(w, fmt.Sprintf("exposehub: this token already has %d sessions open", sessionLimit), http.StatusTooManyRequests)
		return
	}
	defer s.releaseSession(principal.TokenID)

	ws, err := websocket.Accept(w, r, nil)
	if err != nil {
		return // Accept has already written the error response
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	log := s.log.With("session", newID(), "account", principal.Label)
	mux, err := yamux.Server(transport.NetConn(ctx, ws), transport.MuxConfig(log))
	if err != nil {
		log.Error("starting the session failed", "err", err)
		return
	}
	defer mux.Close()

	ctrl, err := acceptStream(mux, handshakeTimeout)
	if err != nil {
		log.Debug("agent sent no control stream", "err", err)
		return
	}
	go func() {
		// The agent opens one stream, the control stream; all others are
		// opened by the server. yamux would keep further streams from the
		// agent, with their data, until someone accepts them, so accept
		// them here: any such stream breaks the protocol and ends the session.
		// The stream itself is left alone: closing it would start yamux's
		// close timer, which keeps the ended session and everything its
		// streams buffered in memory for minutes. Closing the session frees
		// them at once.
		if _, err := mux.AcceptStream(); err == nil {
			log.Info("the agent opened a stream of its own; closing the session")
			_ = mux.Close()
		}
	}()
	var hello proto.Hello
	_ = ctrl.SetReadDeadline(time.Now().Add(handshakeTimeout))
	if err := proto.ReadFrame(ctrl, &hello); err != nil {
		log.Debug("reading hello failed", "err", err)
		return
	}
	_ = ctrl.SetReadDeadline(time.Time{})

	refuse := func(reason string) {
		log.Info("session refused", "reason", reason)
		_ = proto.WriteFrame(ctrl, proto.Welcome{Error: reason})
	}
	reqs, err := s.hostRequests(hello, principal)
	if err != nil {
		refuse(err.Error())
		return
	}

	modify := s.scopeCookies
	if principal.Demo {
		modify = s.demoResponse
	}
	sess := newSession(principal, mux, ctrl, s.rewrite, modify, log)
	if principal.Demo {
		sess.limit = s.demoAllowance(principal.TokenID, time.Now())
	}
	sess.id = newSessionID()
	sess.agentVersion = cleanAgentVersion(hello.AgentVersion)
	sess.connectedAt = time.Now()
	hosts, displaced, err := s.reg.claim(principal, sess, reqs, time.Now())
	if err != nil {
		refuse(err.Error())
		return
	}
	sess.hosts = hosts
	defer func() {
		s.reg.release(sess, time.Now())
		s.track(sess, false)
		sess.close()
	}()
	takenOver := "another agent with the same token opened the same address"
	if principal.Demo {
		takenOver = "another agent connected with the same demo token, and a demo token has one tunnel"
	}
	for _, old := range displaced {
		old.goodbye(takenOver, true)
	}

	welcome := proto.Welcome{}
	if s.agents != nil {
		welcome.AgentVersion = s.agents.Version
	}
	for i, host := range hosts {
		sub, domain, _ := strings.Cut(host, ".")
		if reqs[i].host != "" {
			sub, domain = "", host
			sess.custom = append(sess.custom, host)
		}
		if len(reqs[i].auth) > 0 {
			sess.auth[host] = reqs[i].auth
		}
		logging := LogOff
		if _, fromDashboard := principal.issuer.(*ControlPlane); fromDashboard && s.shipper != nil {
			logging = reqs[i].log
		}
		if principal.Demo && logging == LogBodies {
			// Anyone can open a demo tunnel, and what its visitors send is
			// not theirs to keep.
			logging = LogRequests
		}
		sess.logMode[host] = logging
		if reqs[i].allow != nil {
			sess.allow[host] = reqs[i].allow
		}
		welcome.Tunnels = append(welcome.Tunnels, proto.TunnelInfo{
			Name:      hello.Tunnels[i].Name,
			ID:        host,
			URL:       s.cfg.publicURL(host),
			Domain:    domain,
			Subdomain: sub,
			Protected: len(reqs[i].auth) > 0,
			Logging:   sess.logMode[host],
			Access:    s.describeAccess(sess, host),
		})
	}
	// Visible to the revocation sweep from here. A sweep that ran while this
	// agent was in its handshake did not see it, so ask the issuer again: a
	// token or domain withdrawn before this point is caught by this check,
	// one withdrawn after it by the sweep.
	s.track(sess, true)
	if checker, ok := principal.issuer.(sessionChecker); ok {
		if reason, final := checker.recheck(principal, sess.custom); reason != "" {
			if final {
				refuse(reason)
			} else {
				// No Welcome: the agent sees a dropped connection and retries.
				log.Info("session dropped before it started", "reason", reason)
			}
			return
		}
	}
	// Everything a request needs is in place: from here visitors are served,
	// and the session shows up in the status report.
	sess.ready.Store(true)
	if err := proto.WriteFrame(ctrl, welcome); err != nil {
		log.Debug("writing welcome failed", "err", err)
		return
	}
	log.Info("agent connected", "hosts", hosts, "agent_version", sess.agentVersion)

	<-mux.CloseChan()
	log.Info("agent disconnected", "hosts", hosts)
}

// hostRequests validates a Hello and turns it into hostname requests.
func (s *Server) hostRequests(hello proto.Hello, who Principal) ([]hostReq, error) {
	if hello.Version != proto.Version {
		return nil, fmt.Errorf("the server speaks protocol version %d and the agent version %d; update the older one", proto.Version, hello.Version)
	}
	if n := len(hello.Tunnels); n == 0 || n > maxTunnelsPerSession {
		return nil, fmt.Errorf("an agent may open between 1 and %d tunnels, not %d", maxTunnelsPerSession, n)
	}
	if who.Demo && len(hello.Tunnels) > 1 {
		return nil, errors.New("the demo opens one tunnel per token; start a single tunnel")
	}
	reqs := make([]hostReq, 0, len(hello.Tunnels))
	for _, t := range hello.Tunnels {
		if t.Proto != proto.ProtoHTTP {
			return nil, fmt.Errorf("tunnel protocol %q is not supported", t.Proto)
		}
		if who.Demo && t.Hostname != "" {
			return nil, errors.New("the demo gives out random addresses only; start the tunnel without --hostname")
		}
		auth, err := parseAuth(t.Auth)
		if err != nil {
			return nil, err
		}
		allow, err := parseAllow(t.Allow, who)
		if err != nil {
			return nil, err
		}
		logging := LogRequests
		switch {
		case t.NoLog:
			logging = LogOff
		case t.LogBodies:
			logging = LogBodies
		}
		if t.Hostname != "" {
			host, err := s.customHost(t, who)
			if err != nil {
				return nil, err
			}
			reqs = append(reqs, hostReq{host: host, auth: auth, log: logging, allow: allow})
			continue
		}
		domain := normalizeHost(t.Domain)
		if domain == "" {
			domain = s.cfg.TunnelDomains[0]
		}
		if !s.cfg.hasDomain(domain) {
			return nil, fmt.Errorf("%q is not a tunnel domain of this server", t.Domain)
		}
		sub := strings.ToLower(t.Subdomain)
		if sub != "" && !validLabel(sub) {
			return nil, fmt.Errorf("%q is not a valid subdomain; use letters, digits and hyphens", t.Subdomain)
		}
		if who.Demo && sub != "" && !s.reg.holds(sub+"."+domain, who, time.Now()) {
			// An agent asks for the address it had when it reconnects. One
			// that was away for too long has lost it and gets a new one.
			// Any other name was chosen by a person, which the demo refuses.
			if !randomShaped(sub) {
				return nil, errors.New("the demo gives out random addresses only; start the tunnel without --subdomain")
			}
			sub = ""
		}
		// A name a team saved in the dashboard is that team's, connected or not.
		if reservations, ok := s.auth.(ReservationAuthority); ok && sub != "" {
			if account, saved := reservations.Reservation(sub + "." + domain); saved && account != who.Account {
				return nil, fmt.Errorf("%s.%s %w; pick another name", sub, domain, errHostSaved)
			}
		}
		reqs = append(reqs, hostReq{domain: domain, sub: sub, auth: auth, log: logging, allow: allow})
	}
	return reqs, nil
}

// rewrite prepares a visitor request for the agent. The outbound URL host is
// the tunnel ID the session's transport dials; the Host header stays as sent.
func (s *Server) rewrite(pr *httputil.ProxyRequest) {
	pr.Out.URL.Scheme = "http"
	pr.Out.URL.Host = normalizeHost(pr.In.Host)
	pr.Out.Host = pr.In.Host
	// Rewrite has already dropped any X-Forwarded-* the peer sent, so what is
	// set here can be trusted by the local service.
	pr.SetXForwarded()
	if !s.cfg.trusts(pr.In.RemoteAddr) {
		return
	}
	// The peer is our own reverse proxy: it saw the visitor, we only see it.
	if v := pr.In.Header.Values("X-Forwarded-For"); len(v) > 0 {
		pr.Out.Header["X-Forwarded-For"] = v
	}
	if v := pr.In.Header.Get("X-Forwarded-Proto"); v == "http" || v == "https" {
		pr.Out.Header.Set("X-Forwarded-Proto", v)
	}
}

// isConnectedDomain reports whether host is a domain some team has connected.
func (s *Server) isConnectedDomain(host string) bool {
	domains, _ := s.auth.(DomainAuthority)
	if domains == nil {
		return false
	}
	_, ok := domains.DomainOwner(host)
	return ok
}

// customHost checks that an agent may serve a domain of its account's own.
func (s *Server) customHost(t proto.TunnelRequest, who Principal) (string, error) {
	if t.Subdomain != "" || t.Domain != "" {
		return "", errors.New("ask for a hostname of your own or for a subdomain, not both")
	}
	host := normalizeHost(t.Hostname)
	if host == s.cfg.AgentHost {
		return "", fmt.Errorf("%s %w", host, errHostReserved)
	}
	for _, d := range s.cfg.TunnelDomains {
		if host == d || strings.HasSuffix(host, "."+d) {
			return "", fmt.Errorf("%s is under %s; ask for it as a subdomain", host, d)
		}
	}
	domains, _ := s.auth.(DomainAuthority)
	if domains == nil {
		return "", errors.New("this server does not offer custom domains")
	}
	if account, ok := domains.DomainOwner(host); !ok || account != who.Account {
		return "", fmt.Errorf("%s is not a connected domain of your team; add it in the dashboard first", host)
	}
	return host, nil
}

// CloseSession ends the session with the given id if its agent matches,
// telling it why and not to reconnect, and reports whether there was one. It
// is how a person disconnects a tunnel they left running somewhere.
func (s *Server) CloseSession(id string, match func(Principal) bool, reason string) bool {
	if id == "" {
		return false
	}
	s.mu.Lock()
	var found *session
	for sess := range s.sessions {
		if sess.id == id && match(sess.who) {
			found = sess
			break
		}
	}
	s.mu.Unlock()
	if found == nil {
		return false
	}
	found.goodbye(reason, true)
	return true
}

// CloseSessionsServing ends every session that serves a custom domain for
// which match reports true, telling the agent why and not to reconnect.
func (s *Server) CloseSessionsServing(match func(host string, who Principal) bool, reason string) {
	s.mu.Lock()
	var matched []*session
	for sess := range s.sessions {
		for _, host := range sess.custom {
			if match(host, sess.who) {
				matched = append(matched, sess)
				break
			}
		}
	}
	s.mu.Unlock()
	for _, sess := range matched {
		sess.goodbye(reason, true)
	}
}

// sessionChecker is an Authenticator whose word can change after it was
// given: a token revoked or a domain removed since the agent authenticated.
// recheck returns why the session may not start; final means retrying will
// not help.
type sessionChecker interface {
	recheck(p Principal, custom []string) (reason string, final bool)
}

// cleanAgentVersion keeps what an agent reports as its version printable and
// short, so one agent cannot spoil the status report for everyone.
func cleanAgentVersion(v string) string {
	v = strings.Map(func(r rune) rune {
		if r == utf8.RuneError || !unicode.IsPrint(r) {
			return -1
		}
		return r
	}, v)
	return truncateUTF8(v, maxAgentVersion)
}

// truncateUTF8 cuts s to at most n bytes without splitting a character.
func truncateUTF8(s string, n int) string {
	if len(s) <= n {
		return s
	}
	for n > 0 && !utf8.RuneStart(s[n]) {
		n--
	}
	return s[:n]
}

// scopeCookies makes cookies a tunnel sets for a whole tunnel domain, or a
// parent of one, host-only. All teams' tunnels share the tunnel domain, so
// such a cookie would reach every other team's tunnel.
func (s *Server) scopeCookies(resp *http.Response) error {
	cookies := resp.Header.Values("Set-Cookie")
	if len(cookies) == 0 {
		return nil
	}
	scoped := make([]string, len(cookies))
	for i, c := range cookies {
		scoped[i] = s.hostOnlyCookie(c)
	}
	resp.Header["Set-Cookie"] = scoped
	return nil
}

// hostOnlyCookie drops the Domain attribute of a Set-Cookie value when it
// names a tunnel domain or a parent of one.
func (s *Server) hostOnlyCookie(line string) string {
	parts := strings.Split(line, ";")
	kept := make([]string, 0, len(parts))
	kept = append(kept, parts[0])
	for _, attr := range parts[1:] {
		name, value, _ := strings.Cut(attr, "=")
		if strings.EqualFold(strings.TrimSpace(name), "domain") && s.coversTunnelDomain(value) {
			continue
		}
		kept = append(kept, attr)
	}
	if len(kept) == len(parts) {
		return line
	}
	return strings.Join(kept, ";")
}

// coversTunnelDomain reports whether a cookie's Domain attribute would make
// browsers send it to every tunnel under some tunnel domain.
func (s *Server) coversTunnelDomain(domain string) bool {
	d := strings.ToLower(strings.Trim(strings.TrimSpace(domain), "."))
	if d == "" {
		return false
	}
	for _, td := range s.cfg.TunnelDomains {
		if td == d || strings.HasSuffix(td, "."+d) {
			return true
		}
	}
	return false
}

// holdSession counts a new session of a token, unless it has limit of them.
func (s *Server) holdSession(tokenID string, limit int) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.perToken[tokenID] >= limit {
		return false
	}
	s.perToken[tokenID]++
	return true
}

func (s *Server) releaseSession(tokenID string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.perToken[tokenID]--; s.perToken[tokenID] <= 0 {
		delete(s.perToken, tokenID)
	}
}

func (s *Server) track(sess *session, add bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if add {
		s.sessions[sess] = struct{}{}
	} else {
		delete(s.sessions, sess)
	}
}

// session is one connected agent.
type session struct {
	who   Principal
	hosts []string
	// custom are the hosts that are domains of the account's own.
	custom []string
	// auth holds, per host, the credentials visitors must present. It is
	// filled before the session is announced to the agent and not changed after.
	auth map[string][][sha256.Size]byte
	// logMode holds, per host, what is logged: LogOff, LogRequests or LogBodies.
	logMode map[string]string
	// allow holds, per host, the agent's own limit on who may reach it.
	allow map[string]*agentAllow
	// limit is what a demo token's tunnel may use, shared by all sessions of
	// the token; nil for any other.
	limit *allowance
	// id names the session in the status report, so that the dashboard can
	// ask for this one to be closed. It is random and means nothing else.
	id string
	// agentVersion and connectedAt are for the status report.
	agentVersion string
	connectedAt  time.Time
	// ready turns true once auth and logMode are filled. The registry routes
	// visitors to the session as soon as it claims its hosts, a moment
	// earlier, so requests check this before reading those maps.
	ready     atomic.Bool
	mux       *yamux.Session
	ctrl      *yamux.Stream
	transport *http.Transport
	proxy     *httputil.ReverseProxy
}

func newSession(who Principal, mux *yamux.Session, ctrl *yamux.Stream, rewrite func(*httputil.ProxyRequest), modify func(*http.Response) error, log *slog.Logger) *session {
	sess := &session{who: who, mux: mux, ctrl: ctrl, auth: map[string][][sha256.Size]byte{}, logMode: map[string]string{}, allow: map[string]*agentAllow{}}
	// The transport pools yamux streams the way it would pool TCP connections.
	// The address it dials is the tunnel ID, which for HTTP is the public host.
	sess.transport = &http.Transport{
		DialContext: func(_ context.Context, _, addr string) (net.Conn, error) {
			tunnel, _, err := net.SplitHostPort(addr)
			if err != nil {
				return nil, err
			}
			stream, err := mux.OpenStream()
			if err != nil {
				return nil, err
			}
			if err := proto.WriteFrame(stream, proto.StreamHeader{Tunnel: tunnel}); err != nil {
				stream.Close()
				return nil, err
			}
			return stream, nil
		},
		MaxIdleConnsPerHost: 32,
		IdleConnTimeout:     time.Minute,
		// Bodies pass through untouched; the visitor negotiates encoding with the upstream.
		DisableCompression: true,
	}
	sess.proxy = &httputil.ReverseProxy{
		Transport:      sess.transport,
		Rewrite:        rewrite,
		ModifyResponse: modify,
		ErrorLog:       slog.NewLogLogger(log.Handler(), slog.LevelDebug),
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			log.Debug("proxying to the agent failed", "err", err)
			errorpage.Write(w, r, errorpage.TunnelDidNotAnswer())
		},
	}
	return sess
}

// admits reports whether a visitor may use the tunnel at host. A protected
// tunnel answers 401 here, so a request without the right credentials never
// reaches the agent, let alone the machine behind it.
func (s *session) admits(host string, w http.ResponseWriter, r *http.Request) bool {
	accepted := s.auth[host]
	if len(accepted) == 0 {
		return true
	}
	if user, password, ok := r.BasicAuth(); ok {
		presented := sha256.Sum256([]byte(user + ":" + password))
		for _, want := range accepted {
			if subtle.ConstantTimeCompare(presented[:], want[:]) == 1 {
				// The credentials belong to the tunnel, not to the service
				// behind it, which may use the header for its own purposes.
				r.Header.Del("Authorization")
				return true
			}
		}
	}
	w.Header().Set("WWW-Authenticate", `Basic realm="exposehub tunnel", charset="UTF-8"`)
	errorpage.Write(w, r, errorpage.PasswordRequired())
	return false
}

// parseAuth reads the hashed credentials of a tunnel request.
func parseAuth(entries []string) ([][sha256.Size]byte, error) {
	if len(entries) > maxCredentialsPerTunnel {
		return nil, fmt.Errorf("a tunnel may have at most %d credentials", maxCredentialsPerTunnel)
	}
	var creds [][sha256.Size]byte
	for _, entry := range entries {
		hexSum, ok := strings.CutPrefix(entry, proto.AuthPrefix)
		raw, err := hex.DecodeString(hexSum)
		if !ok || err != nil || len(raw) != sha256.Size {
			return nil, errors.New("a tunnel credential is malformed; update the agent")
		}
		creds = append(creds, [sha256.Size]byte(raw))
	}
	return creds, nil
}

// goodbye tells the agent why the session ends, then closes it.
func (s *session) goodbye(reason string, final bool) {
	_ = s.ctrl.SetWriteDeadline(time.Now().Add(2 * time.Second))
	_ = proto.WriteFrame(s.ctrl, proto.Message{Type: proto.MsgGoodbye, Reason: reason, Final: final})
	s.close()
}

func (s *session) close() {
	s.mux.Close()
	s.transport.CloseIdleConnections()
}

// acceptStream waits for the agent to open a stream and closes the session
// if it does not do so in time.
func acceptStream(mux *yamux.Session, timeout time.Duration) (*yamux.Stream, error) {
	type result struct {
		stream *yamux.Stream
		err    error
	}
	ch := make(chan result, 1)
	go func() {
		stream, err := mux.AcceptStream()
		ch <- result{stream, err}
	}()
	select {
	case r := <-ch:
		return r.stream, r.err
	case <-time.After(timeout):
		mux.Close()
		return nil, errors.New("timed out")
	}
}

// newSessionID returns a random name for a session, long enough that nobody
// can guess the one of a tunnel they do not own.
func newSessionID() string {
	var b [16]byte
	_, _ = rand.Read(b[:])
	return hex.EncodeToString(b[:])
}

func newID() string {
	var b [4]byte
	_, _ = rand.Read(b[:])
	return hex.EncodeToString(b[:])
}
