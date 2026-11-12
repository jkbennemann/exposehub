package e2e

import (
	"context"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

func TestStatusReportListsConnectedAgentsWithoutVisitorData(t *testing.T) {
	cp := newFakeControlPlane(t)
	id := cp.issue("eh_status", 7, "acme")
	h := cp.connect(t, "")

	open := shop(t)
	open.Auth = []string{"ada:s3cret"}
	host, _ := startTunnel(t, h, "eh_status", open)

	rep := server.NewStatusReporter(cp.srv, cp.ts.URL, nodeSecret, "9.9.9", quiet).Report()
	if rep.ServerVersion != "9.9.9" || !rep.RequestLogs {
		t.Errorf("report header = %+v", rep)
	}
	if len(rep.Tunnels) != 1 {
		t.Fatalf("got %d tunnels, want 1: %+v", len(rep.Tunnels), rep.Tunnels)
	}
	tun := rep.Tunnels[0]
	if tun.TokenID != strconv.Itoa(id) || len(tun.Hosts) != 1 || tun.Hosts[0] != host {
		t.Errorf("tunnel = %+v, want token %d on %s", tun, id, host)
	}
	if len(tun.Protected) != 1 || tun.Protected[0] != host {
		t.Errorf("protected = %v, want [%s]", tun.Protected, host)
	}
	if tun.AgentVersion != "test" || time.Since(tun.ConnectedAt) > time.Minute {
		t.Errorf("agent version %q, connected at %v", tun.AgentVersion, tun.ConnectedAt)
	}
}

func TestStatusReporterPostsToTheDashboard(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_status2", 7, "acme")
	h := cp.connect(t, "")
	startTunnel(t, h, "eh_status2", shop(t))

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		server.NewStatusReporter(cp.srv, cp.ts.URL, nodeSecret, "9.9.9", quiet).Run(ctx)
	}()
	t.Cleanup(func() { cancel(); <-done })

	deadline := time.Now().Add(5 * time.Second)
	for len(cp.receivedStatuses()) == 0 {
		if time.Now().After(deadline) {
			t.Fatal("the dashboard received no status report")
		}
		time.Sleep(20 * time.Millisecond)
	}
	if got := cp.receivedStatuses()[0]; got.ServerVersion != "9.9.9" || len(got.Tunnels) != 1 {
		t.Errorf("report = %+v", got)
	}
}

func TestAnOverlongAgentVersionIsCutForTheStatusReport(t *testing.T) {
	cp := newFakeControlPlane(t)
	cp.issue("eh_long", 7, "acme")
	h := cp.connect(t, "")

	_, ctrl := rawAgent(t, h, "eh_long")
	version := strings.Repeat("9", 63) + "é\x00\x1b" + strings.Repeat("x", 200)
	welcome := handshake(t, ctrl, proto.Hello{AgentVersion: version, Tunnels: []proto.TunnelRequest{{Name: "web", Proto: proto.ProtoHTTP}}})
	if welcome.Error != "" {
		t.Fatalf("welcome = %+v", welcome)
	}

	tunnels := server.NewStatusReporter(cp.srv, cp.ts.URL, nodeSecret, "9.9.9", quiet).Report().Tunnels
	if len(tunnels) != 1 {
		t.Fatalf("got %d tunnels, want 1", len(tunnels))
	}
	// 64 bytes would split the é, so it ends before it.
	if got, want := tunnels[0].AgentVersion, strings.Repeat("9", 63); got != want {
		t.Errorf("agent version = %q (%d bytes), want %q", got, len(got), want)
	}
}
