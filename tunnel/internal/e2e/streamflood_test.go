package e2e

import (
	"context"
	"os"
	"os/exec"
	"runtime"
	"sync"
	"testing"
	"time"

	"github.com/hashicorp/yamux"

	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
	"github.com/jkbennemann/exposehub/tunnel/internal/transport"
)

func heapInUse() uint64 {
	runtime.GC()
	runtime.GC()
	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	return m.HeapAlloc
}

// floodingAgent connects like an agent that means harm: a large backlog of
// its own and no wait for the server to accept the streams it opens.
func floodingAgent(t *testing.T, h *harness) *yamux.Session {
	t.Helper()
	ws, status := dialAgent(t, h, "alice-token")
	if ws == nil {
		t.Fatalf("the agent endpoint answered %d", status)
	}
	cfg := transport.MuxConfig(quiet)
	cfg.AcceptBacklog = 1024
	cfg.StreamOpenTimeout = 0
	mux, err := yamux.Client(transport.NetConn(context.Background(), ws), cfg)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { mux.Close() })
	ctrl, err := mux.OpenStream()
	if err != nil {
		t.Fatal(err)
	}
	if w := handshake(t, ctrl, proto.Hello{AgentVersion: "test", Tunnels: []proto.TunnelRequest{{Name: "web", Proto: proto.ProtoHTTP}}}); w.Error != "" {
		t.Fatalf("welcome = %+v", w)
	}
	return mux
}

// flood opens sessions that each open many streams of their own, fills one
// stream window on each, and leaves.
func flood(t *testing.T, h *harness, sessions, streams int) {
	payload := make([]byte, 256<<10) // one stream window
	for range sessions {
		mux := floodingAgent(t, h)
		var wg sync.WaitGroup
		for range streams {
			wg.Add(1)
			go func() {
				defer wg.Done()
				stream, err := mux.OpenStream()
				if err != nil {
					return
				}
				_ = stream.SetWriteDeadline(time.Now().Add(3 * time.Second))
				_, _ = stream.Write(payload)
			}()
		}
		wg.Wait()
		mux.Close()
	}
}

// Ending the session of an agent that opens streams of its own must also let
// go of what those streams carried. Otherwise an agent that connects, floods
// and leaves, again and again, fills the server's memory with sessions that
// are already over, and the limit on open sessions per token never applies.
//
// The flooding agents run in a second process (this test binary, started
// again), so the memory measured here is the server's alone.
func TestStreamsOfEndedSessionsDoNotStayInMemory(t *testing.T) {
	if addr := os.Getenv(floodChildEnv); addr != "" {
		flood(t, &harness{addr: addr}, 8, 256)
		return
	}
	exe, err := os.Executable()
	if err != nil {
		t.Skip("cannot find the test binary to start the flooding agents with")
	}

	h := newHarness(t)
	before := heapInUse()
	const rounds = 6
	for range rounds {
		cmd := exec.Command(exe, "-test.run", "^TestStreamsOfEndedSessionsDoNotStayInMemory$", "-test.count=1")
		cmd.Env = append(os.Environ(), floodChildEnv+"="+h.addr)
		if out, err := cmd.CombinedOutput(); err != nil {
			t.Logf("flooding agents: %v\n%s", err, out)
		}
	}

	// Each session ended when the server saw its first extra stream. Give
	// the server a moment to finish, then look at what it still holds.
	const allowed = 8 << 20
	deadline := time.Now().Add(5 * time.Second)
	var kept uint64
	for {
		kept = 0
		if after := heapInUse(); after > before {
			kept = after - before
		}
		if kept < allowed || time.Now().After(deadline) {
			break
		}
		time.Sleep(200 * time.Millisecond)
	}
	t.Logf("the server holds %d MiB after %d rounds of flooding sessions ended", kept>>20, rounds)
	if kept >= allowed {
		t.Errorf("the server still holds %d MiB after the flooding sessions ended, want less than %d MiB", kept>>20, allowed>>20)
	}
}

// floodChildEnv tells a second run of the test binary to be the flooding
// agents, and names the server to flood.
const floodChildEnv = "EXPOSEHUB_TEST_FLOOD_ADDR"
