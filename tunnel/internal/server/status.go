package server

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"sort"
	"strings"
	"time"
)

const (
	statusPath   = "/internal/v1/status"
	statusPeriod = 15 * time.Second
)

// TunnelStatus describes one connected agent for the dashboard's admin area.
// It carries no visitor data: who connected, on which addresses, since when.
type TunnelStatus struct {
	// ID names the session. The dashboard sends it back in its snapshot to
	// have this one session closed.
	ID string `json:"id"`
	// TokenID is the dashboard's token id, empty for static tokens.
	TokenID      string    `json:"token_id"`
	Account      string    `json:"account"`
	Hosts        []string  `json:"hosts"`
	Protected    []string  `json:"protected"`
	Restricted   []string  `json:"restricted"`
	AgentVersion string    `json:"agent_version"`
	ConnectedAt  time.Time `json:"connected_at"`
}

// StatusReport is what the server tells the dashboard about itself.
type StatusReport struct {
	ServerVersion string    `json:"server_version"`
	AgentRelease  string    `json:"agent_release,omitempty"`
	StartedAt     time.Time `json:"started_at"`
	RequestLogs   bool      `json:"request_logs"`
	// TokensRefreshedAt is when the dashboard last confirmed the token list.
	// TokensStale is true once that is too long ago for the server to accept
	// dashboard tokens: revocations would not reach it.
	TokensRefreshedAt *time.Time     `json:"tokens_refreshed_at,omitempty"`
	TokensStale       bool           `json:"tokens_stale"`
	Tunnels           []TunnelStatus `json:"tunnels"`
}

// Tunnels lists the connected agents, oldest first.
func (s *Server) Tunnels() []TunnelStatus {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := make([]TunnelStatus, 0, len(s.sessions))
	for sess := range s.sessions {
		if !sess.ready.Load() {
			continue // still being checked; it may yet be refused
		}
		st := TunnelStatus{
			ID:           sess.id,
			Account:      sess.who.Label,
			Hosts:        append([]string(nil), sess.hosts...),
			Protected:    []string{},
			Restricted:   []string{},
			AgentVersion: sess.agentVersion,
			ConnectedAt:  sess.connectedAt,
		}
		if _, fromDashboard := sess.who.issuer.(*ControlPlane); fromDashboard {
			st.TokenID = sess.who.TokenID
		}
		for _, h := range sess.hosts {
			if len(sess.auth[h]) > 0 {
				st.Protected = append(st.Protected, h)
			}
			if s.describeAccess(sess, h) != "" {
				st.Restricted = append(st.Restricted, h)
			}
		}
		out = append(out, st)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ConnectedAt.Before(out[j].ConnectedAt) })
	return out
}

// StatusReporter tells the dashboard every few seconds that the server is up,
// which version it runs and which agents are connected. The dashboard keeps
// the last report for a couple of minutes and shows it to its admins.
type StatusReporter struct {
	srv     *Server
	url     string
	secret  string
	version string
	started time.Time
	client  *http.Client
	log     *slog.Logger
}

// NewStatusReporter returns a reporter for srv that posts to the dashboard at baseURL.
func NewStatusReporter(srv *Server, baseURL, secret, version string, log *slog.Logger) *StatusReporter {
	return &StatusReporter{
		srv: srv, url: strings.TrimRight(baseURL, "/") + statusPath, secret: secret, version: version,
		started: time.Now(), client: &http.Client{Timeout: 10 * time.Second}, log: log,
	}
}

// Report builds the current report.
func (r *StatusReporter) Report() StatusReport {
	rep := StatusReport{
		ServerVersion: r.version,
		StartedAt:     r.started,
		RequestLogs:   r.srv.shipper != nil,
		Tunnels:       r.srv.Tunnels(),
	}
	if r.srv.agents != nil {
		rep.AgentRelease = r.srv.agents.Version
	}
	if cp := r.srv.controlPlane(); cp != nil {
		now := time.Now()
		cp.mu.RLock()
		if refreshed := cp.refreshed; !refreshed.IsZero() {
			rep.TokensRefreshedAt = &refreshed
		}
		cp.mu.RUnlock()
		rep.TokensStale = !cp.current(now)
	}
	return rep
}

// controlPlane is the dashboard the server takes tokens from, if any.
func (s *Server) controlPlane() *ControlPlane {
	switch a := s.auth.(type) {
	case *ControlPlane:
		return a
	case Authenticators:
		for _, member := range a {
			if cp, ok := member.(*ControlPlane); ok {
				return cp
			}
		}
	}
	return nil
}

// Run reports until ctx ends. A failed report is logged once until one succeeds.
func (r *StatusReporter) Run(ctx context.Context) {
	tick := time.NewTicker(statusPeriod)
	defer tick.Stop()
	failing := false
	for {
		err := r.post(ctx, r.Report())
		switch {
		case err != nil && !failing && ctx.Err() == nil:
			r.log.Warn("reporting status to the dashboard failed", "err", err)
			failing = true
		case err == nil && failing:
			r.log.Info("reporting status to the dashboard works again")
			failing = false
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

func (r *StatusReporter) post(ctx context.Context, rep StatusReport) error {
	body, err := json.Marshal(rep)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, r.url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+r.secret)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	resp, err := r.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	if resp.StatusCode/100 != 2 {
		return fmt.Errorf("the dashboard answered %s", resp.Status)
	}
	return nil
}
