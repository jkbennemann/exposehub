package server

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
)

// ErrUnauthorized is returned by an Authenticator for an unknown token.
var ErrUnauthorized = errors.New("unknown token")

// Principal says who an agent is.
type Principal struct {
	// Account owns addresses. Agents of one account, a team, share its
	// reserved names.
	Account string
	// TokenID identifies the credential. An address in use can only be taken
	// over by an agent with the same token, which is how a reconnecting agent
	// gets its address back without teammates being able to pull it away.
	TokenID string
	// Label names the account in logs.
	Label string
	// Demo marks a token of the dashboard's demo account, which anyone can
	// use without signing up. Its tunnels get the limits in demo.go.
	Demo bool
	// issuer is the Authenticator that vouched for the agent.
	issuer Authenticator
}

// Authenticator decides who an agent token belongs to.
type Authenticator interface {
	Authenticate(ctx context.Context, token string) (Principal, error)
}

// DomainAuthority knows which account has verified a domain of its own. An
// Authenticator may implement it; without one, custom domains are refused.
type DomainAuthority interface {
	DomainOwner(hostname string) (account string, ok bool)
}

// Authenticators asks several authenticators in order; the first that knows
// the token wins.
type Authenticators []Authenticator

// Authenticate implements Authenticator.
func (as Authenticators) Authenticate(ctx context.Context, token string) (Principal, error) {
	for _, a := range as {
		p, err := a.Authenticate(ctx, token)
		if err == nil {
			return p, nil
		}
		if !errors.Is(err, ErrUnauthorized) {
			return Principal{}, err
		}
	}
	return Principal{}, ErrUnauthorized
}

// DomainOwner implements DomainAuthority by asking the members that are one.
func (as Authenticators) DomainOwner(hostname string) (string, bool) {
	for _, a := range as {
		if d, ok := a.(DomainAuthority); ok {
			if account, ok := d.DomainOwner(hostname); ok {
				return account, true
			}
		}
	}
	return "", false
}

// StaticTokens is an Authenticator backed by a fixed list, for installs that
// run the tunnel server without the control plane. It keeps only token
// hashes, so a lookup never compares secrets byte by byte.
type StaticTokens map[[sha256.Size]byte]Principal

// ParseStaticTokens parses "account:token,account:token".
func ParseStaticTokens(spec string) (StaticTokens, error) {
	tokens := StaticTokens{}
	for _, pair := range strings.Split(spec, ",") {
		pair = strings.TrimSpace(pair)
		if pair == "" {
			continue
		}
		id, token, ok := strings.Cut(pair, ":")
		if !ok || id == "" || token == "" {
			return nil, fmt.Errorf("static token entry must look like account:token")
		}
		hash := sha256.Sum256([]byte(token))
		tokens[hash] = Principal{Account: id, TokenID: "static:" + hex.EncodeToString(hash[:6]), Label: id}
	}
	if len(tokens) == 0 {
		return nil, errors.New("no tokens configured")
	}
	return tokens, nil
}

// Authenticate implements Authenticator.
func (t StaticTokens) Authenticate(_ context.Context, token string) (Principal, error) {
	p, ok := t[sha256.Sum256([]byte(token))]
	if !ok {
		return Principal{}, ErrUnauthorized
	}
	p.issuer = t
	return p, nil
}
