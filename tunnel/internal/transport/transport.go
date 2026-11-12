// Package transport holds the settings both ends of a tunnel session share:
// how a WebSocket becomes a byte stream and how yamux is configured on it.
package transport

import (
	"context"
	"log/slog"
	"net"
	"time"

	"github.com/coder/websocket"
	"github.com/hashicorp/yamux"
)

// NetConn adapts a WebSocket to a net.Conn carrying binary messages. The
// connection closes when ctx is cancelled.
func NetConn(ctx context.Context, ws *websocket.Conn) net.Conn {
	// yamux writes frames up to its window size in one message, far above the
	// library's default 32 KiB read limit. yamux enforces its own windows.
	ws.SetReadLimit(-1)
	return websocket.NetConn(ctx, ws, websocket.MessageBinary)
}

// MuxConfig returns the yamux configuration used by server and agent.
func MuxConfig(log *slog.Logger) *yamux.Config {
	cfg := yamux.DefaultConfig()
	// Pings double as NAT keepalives; 20s stays under common idle timeouts.
	cfg.EnableKeepAlive = true
	cfg.KeepAliveInterval = 20 * time.Second
	cfg.ConnectionWriteTimeout = 15 * time.Second
	cfg.LogOutput = nil
	cfg.Logger = slog.NewLogLogger(log.Handler(), slog.LevelDebug)
	return cfg
}
