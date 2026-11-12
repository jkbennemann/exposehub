package server

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
)

// fakeSnapshots answers the snapshot route with whatever tokens it holds and
// counts how often it is asked.
type fakeSnapshots struct {
	mu     sync.Mutex
	tokens []map[string]any
	asked  int
	ts     *httptest.Server
}

func newFakeSnapshots(t *testing.T) *fakeSnapshots {
	f := &fakeSnapshots{}
	f.ts = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		f.asked++
		body, _ := json.Marshal(map[string]any{"tokens": f.tokens})
		f.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write(body)
	}))
	t.Cleanup(f.ts.Close)
	return f
}

func (f *fakeSnapshots) issue(token string, id, team int) {
	sum := sha256.Sum256([]byte(token))
	f.mu.Lock()
	defer f.mu.Unlock()
	f.tokens = append(f.tokens, map[string]any{"id": id, "team_id": team, "hash": hex.EncodeToString(sum[:])})
}

func (f *fakeSnapshots) count() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.asked
}

func controlPlaneFor(t *testing.T, f *fakeSnapshots) *ControlPlane {
	c, err := NewControlPlane(ControlPlaneConfig{URL: f.ts.URL, Secret: "secret"}, slog.New(slog.NewTextHandler(io.Discard, nil)))
	if err != nil {
		t.Fatal(err)
	}
	return c
}

// A token made in the dashboard a moment after a regular poll has to work at
// once: the agent gives up for good when its token is refused, so "wait for
// the next poll" would tell someone their new token is wrong.
func TestATokenCreatedRightAfterAPollWorksAtOnce(t *testing.T) {
	f := newFakeSnapshots(t)
	c := controlPlaneFor(t, f)
	if err := c.refresh(context.Background(), false); err != nil {
		t.Fatal(err)
	}

	f.issue("eh_fresh", 7, 3)
	p, err := c.Authenticate(context.Background(), "eh_fresh")
	if err != nil {
		t.Fatalf("a token created after the last poll was refused: %v", err)
	}
	if p.Account != "team:3" {
		t.Errorf("account %q", p.Account)
	}
}

// Someone guessing tokens must not make the server fetch the snapshot for
// every guess.
func TestGuessedTokensDoNotEachFetchTheSnapshot(t *testing.T) {
	f := newFakeSnapshots(t)
	c := controlPlaneFor(t, f)
	if err := c.refresh(context.Background(), false); err != nil {
		t.Fatal(err)
	}
	before := f.count()

	for i := 0; i < 50; i++ {
		if _, err := c.Authenticate(context.Background(), "eh_guess"); !errors.Is(err, ErrUnauthorized) {
			t.Fatalf("guess %d: %v", i, err)
		}
	}
	if extra := f.count() - before; extra != 1 {
		t.Errorf("50 guesses made the server fetch the snapshot %d times, want 1", extra)
	}
}
