// Command exposehub-server is the public tunnel server.
package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/jkbennemann/exposehub/tunnel/internal/server"
)

// version is set at build time with -ldflags "-X main.version=...".
var version = "dev"

func main() {
	if len(os.Args) > 1 && os.Args[1] == "healthcheck" {
		url := ""
		if len(os.Args) > 2 {
			url = os.Args[2]
		}
		if err := healthcheck(url, os.Getenv); err != nil {
			fmt.Fprintln(os.Stderr, "exposehub-server healthcheck:", err)
			os.Exit(1)
		}
		return
	}
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "exposehub-server:", err)
		os.Exit(1)
	}
}

func run() error {
	level := slog.LevelInfo
	if v := os.Getenv("EXPOSEHUB_LOG_LEVEL"); v != "" {
		if err := level.UnmarshalText([]byte(v)); err != nil {
			return fmt.Errorf("EXPOSEHUB_LOG_LEVEL: %w", err)
		}
	}
	handler, err := logHandler(os.Getenv("EXPOSEHUB_LOG_FORMAT"), os.Stderr, level)
	if err != nil {
		return err
	}
	log := slog.New(handler).With("version", version)

	cfg, err := server.ConfigFromEnv(os.Getenv)
	if err != nil {
		return err
	}
	// Tokens come from the control plane, from a static list, or from both.
	var auth server.Authenticators
	var controlPlane *server.ControlPlane
	if url := os.Getenv("EXPOSEHUB_CONTROL_PLANE_URL"); url != "" {
		cpCfg := server.ControlPlaneConfig{URL: url, Secret: os.Getenv("EXPOSEHUB_NODE_SECRET")}
		if dir := os.Getenv("EXPOSEHUB_DATA_DIR"); dir != "" {
			cpCfg.CacheFile = filepath.Join(dir, "snapshot.json")
		}
		if cpCfg.MaxStale, err = maxStale(os.Getenv("EXPOSEHUB_CONTROL_PLANE_MAX_STALE")); err != nil {
			return err
		}
		if controlPlane, err = server.NewControlPlane(cpCfg, log); err != nil {
			return err
		}
		auth = append(auth, controlPlane)
	}
	if spec := os.Getenv("EXPOSEHUB_STATIC_TOKENS"); spec != "" {
		tokens, err := server.ParseStaticTokens(spec)
		if err != nil {
			return fmt.Errorf("EXPOSEHUB_STATIC_TOKENS: %w", err)
		}
		auth = append(auth, tokens)
	}
	if len(auth) == 0 {
		return errors.New("no source of agent tokens: set EXPOSEHUB_CONTROL_PLANE_URL or EXPOSEHUB_STATIC_TOKENS")
	}
	srv, err := server.New(cfg, auth, log)
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if controlPlane != nil {
		controlPlane.Attach(srv)
		go controlPlane.Run(ctx)

		// Request logs go to the same dashboard, unless the operator turns them off.
		if os.Getenv("EXPOSEHUB_REQUEST_LOGS") != "off" {
			shipper := server.NewLogShipper(os.Getenv("EXPOSEHUB_CONTROL_PLANE_URL"), os.Getenv("EXPOSEHUB_NODE_SECRET"), log)
			srv.SetLogShipper(shipper)
			go shipper.Run(ctx)
		}

		// Its admins see that the server is up and which agents are connected.
		go server.NewStatusReporter(srv, os.Getenv("EXPOSEHUB_CONTROL_PLANE_URL"), os.Getenv("EXPOSEHUB_NODE_SECRET"), version, log).Run(ctx)
	}
	if err := srv.Run(ctx); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// maxStale reads how long the dashboard's token list is trusted without a
// refresh. Empty means the default; 0 trusts it forever.
func maxStale(v string) (time.Duration, error) {
	if v == "" {
		return 0, nil
	}
	d, err := time.ParseDuration(v)
	if err != nil || d < 0 {
		return 0, fmt.Errorf("EXPOSEHUB_CONTROL_PLANE_MAX_STALE: %q is not a duration like 5m", v)
	}
	if d == 0 {
		return -1, nil
	}
	return d, nil
}

// healthcheck asks a running server whether it is up, for container health
// checks: the image has no shell and no curl. A URL given must answer 200.
// Without one it asks the internal listener's /healthz; when there is no
// internal listener, any answer from the main listener counts, since that
// listener has no health path of its own (every path belongs to tunnels).
func healthcheck(url string, getenv func(string) string) error {
	anyAnswer := false
	if url == "" {
		var err error
		if url, anyAnswer, err = defaultHealthURL(getenv); err != nil {
			return err
		}
	}
	client := &http.Client{Timeout: 3 * time.Second}
	resp, err := client.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK && !anyAnswer {
		return fmt.Errorf("%s answered %s", url, resp.Status)
	}
	return nil
}

func defaultHealthURL(getenv func(string) string) (url string, anyAnswer bool, err error) {
	if addr := getenv("EXPOSEHUB_INTERNAL_LISTEN"); addr != "" {
		_, port, err := net.SplitHostPort(addr)
		if err != nil {
			return "", false, fmt.Errorf("EXPOSEHUB_INTERNAL_LISTEN: %w", err)
		}
		return "http://127.0.0.1:" + port + "/healthz", false, nil
	}
	addr := getenv("EXPOSEHUB_LISTEN")
	if addr == "" {
		addr = ":8080"
	}
	_, port, err := net.SplitHostPort(addr)
	if err != nil {
		return "", false, fmt.Errorf("EXPOSEHUB_LISTEN: %w", err)
	}
	return "http://127.0.0.1:" + port + "/", true, nil
}

// logHandler returns the handler for the server's log: key=value text lines
// by default, one JSON object per line with "json" for log pipelines.
func logHandler(format string, w io.Writer, level slog.Level) (slog.Handler, error) {
	opts := &slog.HandlerOptions{Level: level}
	switch format {
	case "", "text":
		return slog.NewTextHandler(w, opts), nil
	case "json":
		return slog.NewJSONHandler(w, opts), nil
	}
	return nil, fmt.Errorf("EXPOSEHUB_LOG_FORMAT: %q is neither text nor json", format)
}
