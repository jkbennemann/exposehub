// Package proto defines the wire protocol spoken between the agent and the
// tunnel server.
//
// An agent opens one WebSocket to the server and both sides run yamux on top
// of it. The agent opens the first stream, the control stream, and sends
// Hello. The server answers with Welcome and may later send Message frames on
// the same stream. Every further stream is opened by the server, one per
// proxied connection, and starts with a StreamHeader naming the tunnel it
// belongs to. After that header the stream carries the tunnelled bytes.
package proto

import (
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
)

// AuthPrefix marks a hashed credential in TunnelRequest.Auth.
const AuthPrefix = "sha256:"

const (
	// Version is bumped on any change an older peer cannot understand.
	Version = 1

	// AgentPath is the path of the WebSocket endpoint agents connect to.
	AgentPath = "/_exposehub/agent"

	// ProtoHTTP is the only tunnel protocol so far.
	ProtoHTTP = "http"

	// MsgGoodbye tells the agent that the server is about to close the session.
	MsgGoodbye = "goodbye"

	maxFrame = 64 << 10
)

// Hello is the first frame on the control stream, sent by the agent.
type Hello struct {
	Version      int             `json:"version"`
	AgentVersion string          `json:"agent_version"`
	Tunnels      []TunnelRequest `json:"tunnels"`
}

// TunnelRequest asks the server to expose one local service.
type TunnelRequest struct {
	// Name identifies the tunnel within the agent and is echoed in TunnelInfo.
	Name  string `json:"name"`
	Proto string `json:"proto"`
	// Domain selects one of the server's tunnel domains. Empty means the default.
	Domain string `json:"domain,omitempty"`
	// Subdomain is the requested label. Empty means the server picks a random one.
	Subdomain string `json:"subdomain,omitempty"`
	// Hostname asks for a domain of the account's own instead of a subdomain
	// of a tunnel domain. The account must have verified it.
	Hostname string `json:"hostname,omitempty"`
	// LogBodies asks the server to log headers and bodies along with each
	// request, where the server logs requests at all. NoLog asks it to log
	// nothing for this tunnel.
	LogBodies bool `json:"log_bodies,omitempty"`
	NoLog     bool `json:"no_log,omitempty"`
	// Allow limits who may reach the tunnel, on top of what the dashboard
	// says: addresses, networks in CIDR notation, or names of the team's
	// networks. A visitor has to match one of them.
	Allow []string `json:"allow,omitempty"`
	// Auth asks the server to let visitors in only with HTTP Basic
	// credentials. Each entry is "sha256:" plus the hex SHA-256 of
	// "user:password", so the server never holds the password itself.
	Auth []string `json:"auth,omitempty"`
}

// Welcome answers Hello. A non-empty Error means the session was refused.
type Welcome struct {
	Error   string       `json:"error,omitempty"`
	Tunnels []TunnelInfo `json:"tunnels,omitempty"`
	// AgentVersion is the agent version this server hands out for download.
	// An agent with a different version can offer to update itself.
	AgentVersion string `json:"agent_version,omitempty"`
}

// TunnelInfo describes a tunnel the server has opened.
type TunnelInfo struct {
	Name string `json:"name"`
	// ID is what the server puts in StreamHeader.Tunnel. Agents treat it as opaque.
	ID        string `json:"id"`
	URL       string `json:"url"`
	Domain    string `json:"domain"`
	Subdomain string `json:"subdomain"`
	// Logging says what the server logs for this tunnel in the dashboard:
	// "off", "requests" or "bodies". Empty from servers that log nothing.
	Logging string `json:"logging,omitempty"`
	// Protected confirms that the server enforces the requested Auth. An
	// older server ignores Auth, and an agent must not mistake that for
	// protection.
	Protected bool `json:"protected,omitempty"`
	// Access says in words who may reach the tunnel, for example "only from
	// Office VPN (dashboard)". Empty means anyone. An older server leaves it
	// empty even when Allow was sent, and an agent must not take that for
	// enforcement.
	Access string `json:"access,omitempty"`
}

// Message is a frame the server sends on the control stream after Welcome.
// Agents ignore types they do not know.
type Message struct {
	Type   string `json:"type"`
	Reason string `json:"reason,omitempty"`
	// Final tells the agent not to reconnect.
	Final bool `json:"final,omitempty"`
}

// StreamHeader is the first frame on every stream the server opens.
type StreamHeader struct {
	Tunnel string `json:"tunnel"`
}

// WriteFrame writes v as one length-prefixed JSON frame.
func WriteFrame(w io.Writer, v any) error {
	body, err := json.Marshal(v)
	if err != nil {
		return err
	}
	if len(body) > maxFrame {
		return fmt.Errorf("proto: frame of %d bytes exceeds the %d byte limit", len(body), maxFrame)
	}
	buf := make([]byte, 4+len(body))
	binary.BigEndian.PutUint32(buf, uint32(len(body)))
	copy(buf[4:], body)
	_, err = w.Write(buf)
	return err
}

// ReadFrame reads one frame into v. It never reads past the end of the frame,
// so the bytes that follow stay available to the caller.
func ReadFrame(r io.Reader, v any) error {
	var hdr [4]byte
	if _, err := io.ReadFull(r, hdr[:]); err != nil {
		return err
	}
	n := binary.BigEndian.Uint32(hdr[:])
	if n > maxFrame {
		return fmt.Errorf("proto: frame of %d bytes exceeds the %d byte limit", n, maxFrame)
	}
	body := make([]byte, n)
	if _, err := io.ReadFull(r, body); err != nil {
		return err
	}
	return json.Unmarshal(body, v)
}
