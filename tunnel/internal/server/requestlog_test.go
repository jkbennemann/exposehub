package server

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strconv"
	"sync"
	"testing"
)

// logDashboard answers log batches with status(batch) and keeps the ones it accepts.
type logDashboard struct {
	mu       sync.Mutex
	posts    int
	accepted []LogEvent
}

func newLogDashboard(t *testing.T, status func([]LogEvent) int) (*logDashboard, *LogShipper) {
	d := &logDashboard{}
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		zr, err := gzip.NewReader(r.Body)
		if err != nil {
			t.Error(err)
			return
		}
		var batch struct{ Events []LogEvent }
		if err := json.NewDecoder(zr).Decode(&batch); err != nil {
			t.Error(err)
			return
		}
		code := status(batch.Events)
		d.mu.Lock()
		d.posts++
		if code/100 == 2 {
			d.accepted = append(d.accepted, batch.Events...)
		}
		d.mu.Unlock()
		w.WriteHeader(code)
	}))
	t.Cleanup(ts.Close)
	return d, NewLogShipper(ts.URL, "secret", slog.New(slog.NewTextHandler(io.Discard, nil)))
}

func TestARefusedLogBatchIsSplitAndOnlyTheRefusedEventIsDropped(t *testing.T) {
	d, sh := newLogDashboard(t, func(events []LogEvent) int {
		for _, ev := range events {
			if ev.Path == "/poison" {
				return http.StatusUnprocessableEntity
			}
		}
		return http.StatusAccepted
	})
	for i := range 10 {
		path := "/" + strconv.Itoa(i)
		if i == 3 {
			path = "/poison"
		}
		sh.Enqueue(LogEvent{TokenID: "1", Path: path})
	}

	sh.flush(context.Background())
	if len(d.accepted) != 9 {
		t.Errorf("the dashboard accepted %d events, want the 9 it did not refuse", len(d.accepted))
	}
	for _, ev := range d.accepted {
		if ev.Path == "/poison" {
			t.Error("the refused event was accepted")
		}
	}
	if d.posts > 2*10 {
		t.Errorf("%d posts for 10 events", d.posts)
	}
	if len(sh.pending) != 0 || sh.pendingBytes != 0 {
		t.Errorf("%d events (%d bytes) still queued; a refused batch must not be retried as it is", len(sh.pending), sh.pendingBytes)
	}

	posts := d.posts
	sh.flush(context.Background())
	if d.posts != posts {
		t.Error("the refused event was sent again")
	}
}

func TestLogsAreKeptWhenTheDashboardRefusesEveryBatch(t *testing.T) {
	// A wrong node secret is no reason to drop events, nor to split batches.
	d, sh := newLogDashboard(t, func([]LogEvent) int { return http.StatusUnauthorized })
	for range 10 {
		sh.Enqueue(LogEvent{TokenID: "1", Path: "/"})
	}
	sh.flush(context.Background())
	if d.posts != 1 || len(sh.pending) != 10 {
		t.Errorf("%d posts, %d events queued; want 1 post and all 10 kept for a retry", d.posts, len(sh.pending))
	}
}

func TestLogBatchesAreCutBySize(t *testing.T) {
	d, sh := newLogDashboard(t, func([]LogEvent) int { return http.StatusAccepted })
	body := make([]byte, logBodyLimit)
	for range 200 {
		sh.Enqueue(LogEvent{TokenID: "1", Path: "/", Details: &LogDetails{RequestBody: body, ResponseBody: body}})
	}
	sh.flush(context.Background())
	if len(d.accepted) != 200 || d.posts < 2 {
		t.Errorf("%d events in %d posts; want all 200, in batches of at most %d bytes", len(d.accepted), d.posts, logMaxBatchBytes)
	}
}
