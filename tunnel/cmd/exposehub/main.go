// Command exposehub is the agent: it exposes a local service through a
// tunnel server.
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"golang.org/x/term"

	"github.com/jkbennemann/exposehub/tunnel/internal/agent"
	"github.com/jkbennemann/exposehub/tunnel/internal/cliconfig"
	"github.com/jkbennemann/exposehub/tunnel/internal/inspector"
	"github.com/jkbennemann/exposehub/tunnel/internal/selfupdate"
)

// version is set at build time with -ldflags "-X main.version=...".
var version = "dev"

// defaultServer is the tunnel server used when none is given or saved. It is
// set at build time with -ldflags "-X main.defaultServer=..." for builds made
// for one particular install, and left empty for generic builds: an agent
// that guessed would send its token to whatever server it guessed.
var defaultServer = ""

const usage = `Usage:
  exposehub login                    save your agent token
  exposehub http TARGET... [flags]   open a tunnel to each target: a port,
                                     host[:port], URL or folder (./dist);
                                     NAME=TARGET asks for that subdomain
                                     (shop=8000 api=3000)
  exposehub start [NAME...] [flags]  open the tunnels of exposehub.yml
  exposehub mcp [--root DIR]         run as an MCP server for coding agents;
                                     it serves folders inside DIR only
                                     (default: the directory it starts in)
  exposehub logout                   forget the saved token
  exposehub server [URL]             show or change the tunnel server
  exposehub update                   install the agent version the server offers
  exposehub version

Flags for http (they apply to every target):
  --subdomain NAME      ask for this subdomain (one target); random when omitted
  --hostname HOST       use a domain of your own, connected in the dashboard
  --auth USER:PASSWORD  let visitors in only with this name and password;
                        repeat the flag for several people
  --allow ADDR|NETWORK  let only visitors from this address, network
                        (203.0.113.0/24) or named network of your team in;
                        repeat it; adds to the rules set in the dashboard
  --domain DOMAIN       tunnel domain to use when the server offers several
  --host-header MODE    preserve or rewrite; default depends on the target
  --log-bodies          also keep headers and bodies in the dashboard's request log
  --no-log              keep nothing of this tunnel's requests in the dashboard
  --inspect ADDR        where the request inspector listens (default
                        127.0.0.1:4040), or "off"
  --server URL          another tunnel server than the saved one (or EXPOSEHUB_SERVER)
  --token TOKEN         another token than the saved one (or EXPOSEHUB_TOKEN)
  --json                print one JSON object per line, for programs and agents
  --verbose             log connection details

A folder target (./dist, ../site/build or an absolute path) is served by the
agent itself: read only, no folder listings, and nothing whose name starts
with a dot (.env, .git).

Flags for start: --file PATH, --inspect, --json, --server, --token, --verbose.

exposehub.yml, found in the current directory or a parent:
  tunnels:
    shop:
      target: 8000
      subdomain: shop
      auth: ["ada:${SHOP_PASSWORD}"]     # ${NAME} reads the environment
      allow: ["Office VPN", 203.0.113.0/24]
    hooks:
      target: 8000
      subdomain: shop-hooks
      log: bodies                        # requests (default), bodies or off
    docs:
      target: docs.test
      hostname: dev.example.com
      host_header: preserve
`

// repeated collects the values of a flag that may be given several times.
type repeated []string

func (r *repeated) String() string     { return strings.Join(*r, ",") }
func (r *repeated) Set(v string) error { *r = append(*r, v); return nil }

func main() {
	if len(os.Args) < 2 {
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	var err error
	asJSON := false
	switch os.Args[1] {
	case "http":
		asJSON = wantsJSON(os.Args[2:])
		err = runHTTP(os.Args[2:])
	case "start":
		asJSON = wantsJSON(os.Args[2:])
		err = runStart(os.Args[2:])
	case "mcp":
		err = runMCP(os.Args[2:])
	case "login":
		err = runLogin(os.Args[2:])
	case "logout":
		err = runLogout()
	case "update":
		err = runUpdate(os.Args[2:])
	case "server":
		err = runServer(os.Args[2:])
	case "version":
		fmt.Println(version)
	case "help", "-h", "--help":
		fmt.Print(usage)
	default:
		fmt.Fprintf(os.Stderr, "exposehub: unknown command %q\n\n%s", os.Args[1], usage)
		os.Exit(2)
	}
	if err != nil {
		if asJSON {
			newJSONWriter(os.Stdout).write(newErrorEvent(err))
		}
		fmt.Fprintln(os.Stderr, "exposehub:", err)
		os.Exit(1)
	}
}

func runHTTP(args []string) error {
	fs := flag.NewFlagSet("http", flag.ContinueOnError)
	fs.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	saved, err := cliconfig.Load()
	if err != nil {
		return err
	}
	// A flag wins over the environment, which wins over what login saved.
	server := fs.String("server", serverFrom(saved), "")
	token := fs.String("token", firstNonEmpty(os.Getenv("EXPOSEHUB_TOKEN"), saved.Token), "")
	var auth, allow repeated
	fs.Var(&auth, "auth", "")
	fs.Var(&allow, "allow", "")
	subdomain := fs.String("subdomain", "", "")
	domain := fs.String("domain", "", "")
	hostname := fs.String("hostname", "", "")
	hostHeader := fs.String("host-header", agent.HostAuto, "")
	inspect := fs.String("inspect", "127.0.0.1:4040", "")
	logBodies := fs.Bool("log-bodies", false, "")
	noLog := fs.Bool("no-log", false, "")
	verbose := fs.Bool("verbose", false, "")
	asJSON := fs.Bool("json", false, "")

	// The flag package stops at the first positional argument, but people
	// write "http 8000 --subdomain x", so parse around the positionals.
	var targets []string
	for len(args) > 0 {
		if err := fs.Parse(args); err != nil {
			return err
		}
		if args = fs.Args(); len(args) > 0 {
			targets = append(targets, args[0])
			args = args[1:]
		}
	}
	tunnels, err := buildHTTPTunnels(targets, tunnelOptions{
		Subdomain: *subdomain, Hostname: *hostname, Domain: *domain, Auth: auth, Allow: allow,
		LogBodies: *logBodies, NoLog: *noLog, HostHeader: *hostHeader,
	})
	if err != nil {
		return err
	}
	return runTunnels(runOptions{Server: *server, Token: *token, Inspect: *inspect, Verbose: *verbose, JSON: *asJSON}, tunnels)
}

// runStart opens the tunnels of the project file, all of them or the ones named.
func runStart(args []string) error {
	fs := flag.NewFlagSet("start", flag.ContinueOnError)
	fs.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	saved, err := cliconfig.Load()
	if err != nil {
		return err
	}
	server := fs.String("server", serverFrom(saved), "")
	token := fs.String("token", firstNonEmpty(os.Getenv("EXPOSEHUB_TOKEN"), saved.Token), "")
	file := fs.String("file", "", "")
	inspect := fs.String("inspect", "127.0.0.1:4040", "")
	verbose := fs.Bool("verbose", false, "")
	asJSON := fs.Bool("json", false, "")
	var names []string
	for len(args) > 0 {
		if err := fs.Parse(args); err != nil {
			return err
		}
		if args = fs.Args(); len(args) > 0 {
			names = append(names, args[0])
			args = args[1:]
		}
	}
	path := *file
	if path == "" {
		wd, err := os.Getwd()
		if err != nil {
			return err
		}
		if path, err = findProjectFile(wd); err != nil {
			return err
		}
	}
	tunnels, err := loadProject(path, names, os.LookupEnv)
	if err != nil {
		return err
	}
	if !*asJSON {
		fmt.Printf("Project     %s\n", path)
	}
	return runTunnels(runOptions{Server: *server, Token: *token, Inspect: *inspect, Verbose: *verbose, JSON: *asJSON}, tunnels)
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}

// runLogin saves the server and the token. The token is typed or pasted at a
// prompt, so it never appears in the shell history or the process list.
func runLogin(args []string) error {
	saved, err := cliconfig.Load()
	if err != nil {
		return err
	}
	fs := flag.NewFlagSet("login", flag.ContinueOnError)
	fs.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	server := fs.String("server", serverFrom(saved), "")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if fs.NArg() > 0 {
		return errors.New("login takes no arguments; the token is asked for")
	}
	if *server == "" {
		return errors.New("which tunnel server? run: exposehub login --server https://connect.example.com")
	}
	base, err := agent.HTTPBase(*server)
	if err != nil {
		return err
	}

	// Say where the token is going before it is typed.
	fmt.Fprintf(os.Stderr, "Logging in to %s\n", base)
	token, err := readSecret("Paste your agent token from the dashboard: ")
	if err != nil {
		return err
	}
	if token == "" {
		return errors.New("no token entered; nothing was saved")
	}
	path, err := cliconfig.Save(cliconfig.Config{Server: base, Token: token})
	if err != nil {
		return err
	}
	fmt.Printf("Saved to %s. Start a tunnel with: exposehub http 8000\n", path)
	return nil
}

// runLogout forgets the token. The server stays saved, so logging in again
// needs no flags.
func runLogout() error {
	saved, err := cliconfig.Load()
	if err != nil {
		return err
	}
	var path string
	if saved.Server == "" {
		path, err = cliconfig.Remove()
	} else {
		path, err = cliconfig.Save(cliconfig.Config{Server: saved.Server})
	}
	if err != nil {
		return err
	}
	fmt.Printf("Removed the token from %s. It stays valid until you revoke it in the dashboard.\n", path)
	return nil
}

// serverFrom returns the server to use when no flag names one: the
// environment, then the saved one, then the built-in default.
func serverFrom(saved cliconfig.Config) string {
	return firstNonEmpty(os.Getenv("EXPOSEHUB_SERVER"), saved.Server, defaultServer)
}

// runServer shows the tunnel server, or saves another one.
//
//	exposehub server          the server in use, and where that setting comes from
//	exposehub server --saved  only the saved one, empty if none (for scripts)
//	exposehub server URL      save URL; a token saved for another server is dropped
func runServer(args []string) error {
	saved, err := cliconfig.Load()
	if err != nil {
		return err
	}
	fs := flag.NewFlagSet("server", flag.ContinueOnError)
	fs.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	onlySaved := fs.Bool("saved", false, "")
	if err := fs.Parse(args); err != nil {
		return err
	}

	switch fs.NArg() {
	case 0:
		if *onlySaved {
			if saved.Server != "" {
				fmt.Println(saved.Server)
			}
			return nil
		}
		switch {
		case os.Getenv("EXPOSEHUB_SERVER") != "":
			fmt.Printf("%s (from EXPOSEHUB_SERVER)\n", os.Getenv("EXPOSEHUB_SERVER"))
		case saved.Server != "":
			fmt.Printf("%s (saved)\n", saved.Server)
		case defaultServer != "":
			fmt.Printf("%s (built in)\n", defaultServer)
		default:
			fmt.Println("No tunnel server set. Run: exposehub server https://connect.example.com")
		}
		return nil
	case 1:
	default:
		return errors.New("give one server address, for example: exposehub server https://connect.example.com")
	}

	base, err := agent.HTTPBase(fs.Arg(0))
	if err != nil {
		return err
	}
	next := cliconfig.Config{Server: base}
	if saved.Server == base {
		next.Token = saved.Token
	}
	path, err := cliconfig.Save(next)
	if err != nil {
		return err
	}
	fmt.Printf("Saved %s as the tunnel server in %s.\n", base, path)
	if saved.Token != "" && next.Token == "" {
		fmt.Println("The token saved for the previous server was removed. Log in again: exposehub login")
	}
	return nil
}

// readSecret asks for a value without echoing it. When input is piped in, it
// reads one line instead, so scripts can log in too.
func readSecret(prompt string) (string, error) {
	fd := int(os.Stdin.Fd())
	if !term.IsTerminal(fd) {
		line, err := bufio.NewReader(os.Stdin).ReadString('\n')
		if err != nil && line == "" {
			return "", err
		}
		return strings.TrimSpace(line), nil
	}
	fmt.Fprint(os.Stderr, prompt)
	secret, err := term.ReadPassword(fd)
	fmt.Fprintln(os.Stderr)
	return strings.TrimSpace(string(secret)), err
}

// runUpdate replaces this agent with the build the server offers.
func runUpdate(args []string) error {
	saved, err := cliconfig.Load()
	if err != nil {
		return err
	}
	fs := flag.NewFlagSet("update", flag.ContinueOnError)
	fs.Usage = func() { fmt.Fprint(os.Stderr, usage) }
	server := fs.String("server", serverFrom(saved), "")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if *server == "" {
		return errors.New("which server should the update come from? run: exposehub login, or pass --server")
	}
	base, err := agent.HTTPBase(*server)
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	client := &http.Client{}
	rel, err := selfupdate.Fetch(ctx, client, base)
	if err != nil {
		return err
	}
	file, ok := rel.Files[selfupdate.Platform()]
	if !ok {
		return fmt.Errorf("%s offers no agent for %s", base, selfupdate.Platform())
	}
	if rel.Version == version {
		fmt.Printf("exposehub %s is the version %s offers; nothing to do.\n", version, base)
		return rememberServer(saved, base)
	}
	target, err := os.Executable()
	if err != nil {
		return err
	}
	if target, err = filepath.EvalSymlinks(target); err != nil {
		return err
	}
	fmt.Printf("Updating %s from %s to %s\n", target, version, rel.Version)
	if err := selfupdate.Install(ctx, client, base, file, target); err != nil {
		return err
	}
	fmt.Printf("Done. %s is now exposehub %s.\n", target, rel.Version)
	return rememberServer(saved, base)
}

// rememberServer saves the server an update came from when none is saved
// yet. An agent that only knew its server from a built-in default (a build
// made for one install) is often replaced by a generic build without one;
// this keeps it pointed at the same server. A saved server is left alone.
func rememberServer(saved cliconfig.Config, base string) error {
	if saved.Server != "" {
		return nil
	}
	saved.Server = base
	if _, err := cliconfig.Save(saved); err != nil {
		return fmt.Errorf("the update worked, but saving %s as the server failed: %w", base, err)
	}
	return nil
}

// serveInspector starts the inspector page on addr. With tryNext, a port in
// use is not an error: the next nine ports are tried, so a second agent on
// the same machine gets its own inspector.
func serveInspector(ctx context.Context, store *inspector.Store, addr string, tryNext bool) (string, error) {
	host, portText, err := net.SplitHostPort(addr)
	if err != nil {
		return "", fmt.Errorf("--inspect %q: %w", addr, err)
	}
	if ip := net.ParseIP(host); host != "localhost" && (ip == nil || !ip.IsLoopback()) {
		// The page shows captured requests, credentials included.
		return "", fmt.Errorf("--inspect %q: the inspector only listens on this machine (127.0.0.1, ::1 or localhost)", addr)
	}
	port, err := strconv.Atoi(portText)
	if err != nil {
		return "", fmt.Errorf("--inspect %q: the port is not a number", addr)
	}
	attempts := 1
	if tryNext {
		attempts = 10
	}
	var ln net.Listener
	for i := range attempts {
		if ln, err = net.Listen("tcp", net.JoinHostPort(host, strconv.Itoa(port+i))); err == nil {
			break
		}
	}
	if err != nil {
		return "", fmt.Errorf("the inspector cannot listen on %s (%w); choose another address with --inspect, or turn it off with --inspect off", addr, err)
	}
	srv := &http.Server{Handler: inspector.Handler(store), ReadHeaderTimeout: 10 * time.Second}
	go func() { _ = srv.Serve(ln) }()
	go func() {
		<-ctx.Done()
		_ = srv.Close()
	}()
	return ln.Addr().String(), nil
}
