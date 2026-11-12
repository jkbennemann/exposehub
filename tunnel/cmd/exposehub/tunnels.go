package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/url"
	"os"
	"os/signal"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"syscall"
	"time"

	"gopkg.in/yaml.v3"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/inspector"
	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
)

// maxTunnels matches what the server accepts in one session.
const maxTunnels = 10

// projectFiles are the names "exposehub start" looks for, in each directory
// from the current one up.
var projectFiles = []string{"exposehub.yml", "exposehub.yaml"}

var labelPattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)

// tunnelOptions are the settings one tunnel can have, from flags or a project file.
type tunnelOptions struct {
	Subdomain  string
	Hostname   string
	Domain     string
	Auth       []string
	Allow      []string
	LogBodies  bool
	NoLog      bool
	HostHeader string
}

// runOptions are the settings of the agent as a whole.
type runOptions struct {
	Server  string
	Token   string
	Inspect string
	Verbose bool
}

// splitTarget reads "shop=8000" as the name shop and the target 8000. A
// target without a name has none.
func splitTarget(arg string) (name, target string, err error) {
	name, target, ok := strings.Cut(arg, "=")
	if !ok {
		return "", arg, nil
	}
	name = strings.ToLower(strings.TrimSpace(name))
	if !labelPattern.MatchString(name) {
		return "", "", fmt.Errorf("%q is not a valid name for a tunnel; use letters, digits and hyphens", name)
	}
	if strings.TrimSpace(target) == "" {
		return "", "", fmt.Errorf("%s= needs a target, for example %s=8000", name, name)
	}
	return name, target, nil
}

// buildHTTPTunnels turns the targets of "exposehub http" into tunnels. Every
// tunnel gets the shared options; a "name=" prefix asks for that subdomain.
func buildHTTPTunnels(targets []string, shared tunnelOptions) ([]agent.Tunnel, error) {
	switch n := len(targets); {
	case n == 0:
		return nil, errors.New("give a target, for example: exposehub http 8000, or several: exposehub http shop=8000 api=3000")
	case n > maxTunnels:
		return nil, fmt.Errorf("one agent opens at most %d tunnels", maxTunnels)
	case n > 1 && (shared.Subdomain != "" || shared.Hostname != ""):
		return nil, errors.New("--subdomain and --hostname name one tunnel; with several targets, name each one (shop=8000 api=3000) or use a project file")
	}
	tunnels := make([]agent.Tunnel, 0, len(targets))
	seen := map[string]bool{}
	for i, arg := range targets {
		name, target, err := splitTarget(arg)
		if err != nil {
			return nil, err
		}
		opts := shared
		if name != "" {
			if shared.Subdomain != "" || shared.Hostname != "" {
				return nil, fmt.Errorf("%s= already names the tunnel; leave out --subdomain and --hostname", name)
			}
			opts.Subdomain = name
		}
		id := name
		if id == "" {
			id = fmt.Sprintf("tunnel-%d", i+1)
		}
		if seen[id] {
			return nil, fmt.Errorf("the name %s is used twice", id)
		}
		seen[id] = true
		t, err := makeTunnel(id, target, opts)
		if err != nil {
			return nil, err
		}
		tunnels = append(tunnels, t)
	}
	return tunnels, nil
}

// makeTunnel checks one tunnel's options and builds it.
func makeTunnel(name, target string, o tunnelOptions) (agent.Tunnel, error) {
	upstream, err := agent.ParseUpstream(target)
	if err != nil {
		return agent.Tunnel{}, fmt.Errorf("%s: %w", name, err)
	}
	if o.HostHeader == "" {
		o.HostHeader = agent.HostAuto
	}
	switch o.HostHeader {
	case agent.HostAuto, agent.HostPreserve, agent.HostRewrite:
	default:
		return agent.Tunnel{}, fmt.Errorf("%s: host header must be %s or %s", name, agent.HostPreserve, agent.HostRewrite)
	}
	if o.LogBodies && o.NoLog {
		return agent.Tunnel{}, fmt.Errorf("%s: logging bodies and logging nothing contradict each other; choose one", name)
	}
	if o.Hostname != "" && (o.Subdomain != "" || o.Domain != "") {
		return agent.Tunnel{}, fmt.Errorf("%s: a hostname of your own cannot be combined with a subdomain or a domain", name)
	}
	for _, pair := range o.Auth {
		if user, password, ok := strings.Cut(pair, ":"); !ok || user == "" || password == "" {
			return agent.Tunnel{}, fmt.Errorf("%s: a password takes a name and a password joined by a colon, for example ada:s3cret", name)
		}
	}
	for _, a := range o.Allow {
		if strings.TrimSpace(a) == "" {
			return agent.Tunnel{}, fmt.Errorf("%s: an empty value to allow", name)
		}
	}
	return agent.Tunnel{
		Name: name, Upstream: upstream, Domain: o.Domain, Subdomain: o.Subdomain, Hostname: o.Hostname,
		HostHeader: o.HostHeader, Auth: o.Auth, Allow: o.Allow, LogBodies: o.LogBodies, NoLog: o.NoLog,
	}, nil
}

// projectTunnel is one entry under "tunnels:" in exposehub.yml.
type projectTunnel struct {
	Target     string   `yaml:"target"`
	Subdomain  string   `yaml:"subdomain"`
	Hostname   string   `yaml:"hostname"`
	Domain     string   `yaml:"domain"`
	Auth       []string `yaml:"auth"`
	Allow      []string `yaml:"allow"`
	Log        string   `yaml:"log"`
	HostHeader string   `yaml:"host_header"`
}

// findProjectFile looks for exposehub.yml in dir and its parents. The file
// fills its values from the environment and decides where requests go, so a
// file that another account could have put there, as in /tmp, is refused;
// --file still loads it.
func findProjectFile(dir string) (string, error) {
	for {
		for _, name := range projectFiles {
			p := filepath.Join(dir, name)
			if info, err := os.Stat(p); err == nil && !info.IsDir() {
				if err := trustedProjectFile(p); err != nil {
					return "", fmt.Errorf("found %s, but it %v, so it is not used; if you trust it, give it with --file", p, err)
				}
				return p, nil
			}
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return "", errors.New("no exposehub.yml here or in a parent directory; create one (see exposehub help) or give --file")
		}
		dir = parent
	}
}

// loadProject reads a project file and returns its tunnels in the order they
// are written, limited to only when given. ${NAME} is replaced by the
// environment variable NAME, so passwords can stay out of the file; a
// variable that is not set is an error, never an empty password.
func loadProject(path string, only []string, lookup func(string) (string, bool)) ([]agent.Tunnel, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var doc struct {
		Tunnels yaml.Node `yaml:"tunnels"`
	}
	if err := yaml.Unmarshal(raw, &doc); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if doc.Tunnels.Kind != yaml.MappingNode || len(doc.Tunnels.Content) == 0 {
		return nil, fmt.Errorf("%s has no tunnels; list them under \"tunnels:\"", path)
	}
	want := map[string]bool{}
	for _, n := range only {
		want[strings.ToLower(n)] = true
	}
	var tunnels []agent.Tunnel
	found := map[string]bool{}
	for i := 0; i+1 < len(doc.Tunnels.Content); i += 2 {
		name := strings.ToLower(doc.Tunnels.Content[i].Value)
		if !labelPattern.MatchString(name) {
			return nil, fmt.Errorf("%s: %q is not a valid tunnel name; use letters, digits and hyphens", path, name)
		}
		found[name] = true
		if len(want) > 0 && !want[name] {
			continue
		}
		var pt projectTunnel
		node := doc.Tunnels.Content[i+1]
		if err := expandNode(node, lookup); err != nil {
			return nil, fmt.Errorf("%s, tunnel %s: %w", path, name, err)
		}
		if err := decodeStrict(node, &pt); err != nil {
			return nil, fmt.Errorf("%s, tunnel %s: %w", path, name, err)
		}
		if pt.Target == "" {
			return nil, fmt.Errorf("%s, tunnel %s: target is missing, for example target: 8000", path, name)
		}
		opts := tunnelOptions{
			Subdomain: pt.Subdomain, Hostname: pt.Hostname, Domain: pt.Domain,
			Auth: pt.Auth, Allow: pt.Allow, HostHeader: pt.HostHeader,
		}
		switch pt.Log {
		case "", "requests":
		case "bodies":
			opts.LogBodies = true
		case "off":
			opts.NoLog = true
		default:
			return nil, fmt.Errorf("%s, tunnel %s: log must be requests, bodies or off", path, name)
		}
		t, err := makeTunnel(name, pt.Target, opts)
		if err != nil {
			return nil, fmt.Errorf("%s: %w", path, err)
		}
		tunnels = append(tunnels, t)
	}
	for n := range want {
		if !found[n] {
			return nil, fmt.Errorf("%s has no tunnel called %s", path, n)
		}
	}
	if len(tunnels) > maxTunnels {
		return nil, fmt.Errorf("one agent opens at most %d tunnels; start a few by name: exposehub start shop api", maxTunnels)
	}
	return tunnels, nil
}

// decodeStrict decodes node into v and refuses keys v does not have, so a
// typo such as "subdmain" is reported instead of silently ignored.
func decodeStrict(node *yaml.Node, v any) error {
	out, err := yaml.Marshal(node)
	if err != nil {
		return err
	}
	dec := yaml.NewDecoder(strings.NewReader(string(out)))
	dec.KnownFields(true)
	return dec.Decode(v)
}

var envRef = regexp.MustCompile(`\$\{([A-Za-z_][A-Za-z0-9_]*)\}`)

// expandNode replaces ${NAME} in every scalar under node.
func expandNode(node *yaml.Node, lookup func(string) (string, bool)) error {
	if node.Kind == yaml.ScalarNode {
		var missing string
		node.Value = envRef.ReplaceAllStringFunc(node.Value, func(ref string) string {
			name := envRef.FindStringSubmatch(ref)[1]
			v, ok := lookup(name)
			if !ok && missing == "" {
				missing = name
			}
			return v
		})
		if missing != "" {
			return fmt.Errorf("the environment variable %s is not set", missing)
		}
		return nil
	}
	for _, child := range node.Content {
		if err := expandNode(child, lookup); err != nil {
			return err
		}
	}
	return nil
}

// runTunnels connects the agent with the given tunnels and prints what the
// server says about each. It stops with an error if the server does not
// confirm a restriction that was asked for.
func runTunnels(o runOptions, tunnels []agent.Tunnel) error {
	if o.Server == "" || o.Token == "" {
		return errors.New("not logged in; run: exposehub login")
	}
	level := slog.LevelWarn
	if o.Verbose {
		level = slog.LevelDebug
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	ctx, cancel := context.WithCancelCause(ctx)
	defer cancel(nil)

	var store *inspector.Store
	if o.Inspect != "off" && o.Inspect != "false" {
		store = inspector.NewStore()
		addr, err := serveInspector(ctx, store, o.Inspect, o.Inspect == "127.0.0.1:4040")
		if err != nil {
			return err
		}
		fmt.Printf("Inspector   http://%s\n", addr)
	}

	byName := map[string]agent.Tunnel{}
	width := 0
	for _, t := range tunnels {
		byName[t.Name] = t
		width = max(width, len(t.Name))
	}
	several := len(tunnels) > 1
	labelOf := func(name string) string {
		if !several {
			return ""
		}
		return fmt.Sprintf("%-*s  ", width, name)
	}
	// Whether the local services run is checked once, at the first connect:
	// a reconnect says nothing new about them.
	var probed bool

	err := agent.Run(ctx, agent.Config{
		Inspector: store,
		Server:    o.Server,
		Token:     o.Token,
		Version:   version,
		Logger:    slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: level})),
		Tunnels:   tunnels,
		OnConnect: func(infos []proto.TunnelInfo) {
			for _, info := range infos {
				t := byName[info.Name]
				label := labelOf(info.Name)
				protection := ""
				if info.Protected {
					protection = "  (password protected)"
				}
				fmt.Printf("Forwarding  %s%s  ->  %s%s\n", label, info.URL, upstreamString(t.Upstream), protection)
				if len(t.Allow) > 0 && info.Access == "" {
					cancel(fmt.Errorf("the server did not confirm the limit on who may reach %s (it may be an older version); stopped so the tunnel is not open to everyone", info.URL))
					return
				}
				if info.Access != "" {
					fmt.Printf("Access      %s%s\n", label, info.Access)
				}
				if several {
					switch info.Logging {
					case "requests":
						fmt.Printf("Logging     %sa summary of each request\n", label)
					case "bodies":
						fmt.Printf("Logging     %seach request, with headers and bodies\n", label)
					case "off":
						fmt.Printf("Logging     %snothing\n", label)
					}
				}
			}
			// One tunnel gets the full sentence, as before.
			if !several && len(infos) == 1 {
				switch infos[0].Logging {
				case "requests":
					fmt.Println("Logging     a summary of each request goes to your request log in the dashboard (--no-log to stop)")
				case "bodies":
					fmt.Println("Logging     each request, with headers and bodies, goes to your request log in the dashboard")
				}
			}
			if !probed {
				probed = true
				for _, line := range notRunning(ctx, tunnels, labelOf) {
					fmt.Println(line)
				}
			}
		},
		OnNotice: func(tunnel, text string) {
			fmt.Printf("Note        %s%s\n", labelOf(tunnel), text)
		},
		OnNewerAgent: func(offered string) {
			fmt.Fprintf(os.Stderr, "The server offers agent %s (this is %s). Update with: exposehub update\n", offered, version)
		},
		OnRequest: func(r agent.Request) {
			replay := ""
			if r.Replay {
				replay = "  (replay)"
			}
			fmt.Printf("%s  %s%-7s %-3d %8s  %s%s\n", time.Now().Format("15:04:05"),
				labelOf(r.Tunnel), r.Method, r.Status, r.Duration.Round(time.Millisecond), r.Path, replay)
		},
	})
	if cause := context.Cause(ctx); cause != nil && !errors.Is(cause, context.Canceled) {
		return cause
	}
	return err
}

// notRunning returns one line for each tunnel whose local service accepts no
// connection yet, in the order of tunnels. The tunnel stays open either way:
// people start it before their service as often as after.
func notRunning(ctx context.Context, tunnels []agent.Tunnel, label func(string) string) []string {
	down := make([]bool, len(tunnels))
	var wg sync.WaitGroup
	for i, t := range tunnels {
		wg.Go(func() { down[i] = agent.Reachable(ctx, t.Upstream) != nil })
	}
	wg.Wait()
	var lines []string
	for i, t := range tunnels {
		if down[i] {
			lines = append(lines, fmt.Sprintf("Waiting     %snothing answers at %s yet; visitors see an error page until it does", label(t.Name), upstreamString(t.Upstream)))
		}
	}
	return lines
}

func upstreamString(u *url.URL) string {
	if u == nil {
		return ""
	}
	return u.String()
}
