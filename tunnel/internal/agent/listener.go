package agent

import (
	"context"
	"net"
	"sync"
	"time"

	"github.com/hashicorp/yamux"

	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
)

type tunnelKey struct{}

// tunnelConn is a stream whose header has been read.
type tunnelConn struct {
	net.Conn
	tunnel string
}

// connContext makes the tunnel ID of a connection available to its requests.
func connContext(ctx context.Context, c net.Conn) context.Context {
	if tc, ok := c.(*tunnelConn); ok {
		return context.WithValue(ctx, tunnelKey{}, tc.tunnel)
	}
	return ctx
}

// streamListener turns the streams the server opens into connections for an
// http.Server. Each stream's header is read on its own goroutine, so a stream
// that is slow to send it cannot hold up the others.
type streamListener struct {
	mux   *yamux.Session
	conns chan net.Conn
	done  chan struct{}
	once  sync.Once
}

func newStreamListener(mux *yamux.Session) *streamListener {
	l := &streamListener{mux: mux, conns: make(chan net.Conn), done: make(chan struct{})}
	go l.run()
	return l
}

func (l *streamListener) run() {
	for {
		stream, err := l.mux.AcceptStream()
		if err != nil {
			l.Close()
			return
		}
		go l.admit(stream)
	}
}

func (l *streamListener) admit(stream *yamux.Stream) {
	var hdr proto.StreamHeader
	_ = stream.SetReadDeadline(time.Now().Add(10 * time.Second))
	if err := proto.ReadFrame(stream, &hdr); err != nil {
		stream.Close()
		return
	}
	_ = stream.SetReadDeadline(time.Time{})
	select {
	case l.conns <- &tunnelConn{Conn: stream, tunnel: hdr.Tunnel}:
	case <-l.done:
		stream.Close()
	}
}

func (l *streamListener) Accept() (net.Conn, error) {
	select {
	case c := <-l.conns:
		return c, nil
	case <-l.done:
		return nil, net.ErrClosed
	}
}

func (l *streamListener) Close() error {
	l.once.Do(func() { close(l.done) })
	return nil
}

func (l *streamListener) Addr() net.Addr { return l.mux.LocalAddr() }
