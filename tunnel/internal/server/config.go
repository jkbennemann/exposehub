package server

import (
	"errors"
	"fmt"
	"net"
	"net/netip"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Config is everything the tunnel server needs to start. Nothing about an
// install is compiled in: domains, ports and certificates all come from here.
type Config struct {
	// Listen is the address visitors and agents connect to.
	Listen string
	// InternalListen is an optional second address for the private network
	// next to the server: a health check and the on-demand certificate check
	// a reverse proxy asks (see InternalHandler). Empty turns it off. It must
	// not be reachable from the internet.
	InternalListen string
	// TLSAskTunnelNames lets the certificate check say yes to names under a
	// tunnel domain that a tunnel holds, for proxies that get one certificate
	// per tunnel instead of a wildcard.
	TLSAskTunnelNames bool
	// TLSAskNameLimit is how many different tunnel names of one account the
	// certificate check approves within TLSAskNameWindow. Each approval costs
	// a certificate from the install's shared Let's Encrypt allowance, which
	// one account must not be able to use up. Defaults to 10 per week.
	TLSAskNameLimit  int
	TLSAskNameWindow time.Duration
	// TLSAskTotalLimit is how many different names under all tunnel domains together
	// the check approves within the window, across all accounts. Let's
	// Encrypt issues 50 new certificates per registered domain per week;
	// the default of 40 leaves room for the agent host and teams' domains.
	// Negative means no install-wide limit. It must be above TLSAskNameLimit.
	TLSAskTotalLimit int
	// AgentHost is the hostname agents connect to, for example connect.example.eu.
	AgentHost string
	// TunnelDomains are the domains tunnels get subdomains of. The first is the default.
	TunnelDomains []string
	// TLSCertFile and TLSKeyFile enable TLS with an operator-supplied certificate.
	// Without them the server speaks plain HTTP, for use behind another proxy.
	TLSCertFile string
	TLSKeyFile  string
	// PublicScheme and PublicPort describe how visitors reach the server and are
	// used to build tunnel URLs. They default to what Listen and TLS imply.
	PublicScheme string
	PublicPort   string
	// TrustedProxies are the networks of reverse proxies in front of the server.
	// X-Forwarded-For and X-Forwarded-Proto are believed only when the
	// connection comes from one of them; from anyone else they are discarded.
	TrustedProxies []netip.Prefix
	// CustomDomainHeaders are added to every response on a domain a team has
	// connected, including the server's own error pages. A proxy in front can
	// use them to treat those domains differently from the operator's own:
	// for example, to leave includeSubDomains out of the HSTS header there.
	CustomDomainHeaders map[string]string
	// AgentDownloads is a directory with agent builds and their latest.json.
	// When set, the agent host also serves /install.sh and /agent/.
	AgentDownloads string
	// GracePeriod is how long a subdomain stays reserved for its owner after
	// the agent disconnects.
	GracePeriod time.Duration
}

// ConfigFromEnv reads the configuration from EXPOSEHUB_* variables.
func ConfigFromEnv(getenv func(string) string) (Config, error) {
	cfg := Config{
		Listen:         getenv("EXPOSEHUB_LISTEN"),
		InternalListen: getenv("EXPOSEHUB_INTERNAL_LISTEN"),
		AgentHost:      getenv("EXPOSEHUB_AGENT_HOST"),
		TLSCertFile:    getenv("EXPOSEHUB_TLS_CERT_FILE"),
		TLSKeyFile:     getenv("EXPOSEHUB_TLS_KEY_FILE"),
		PublicScheme:   getenv("EXPOSEHUB_PUBLIC_SCHEME"),
		PublicPort:     getenv("EXPOSEHUB_PUBLIC_PORT"),

		AgentDownloads: getenv("EXPOSEHUB_AGENT_DOWNLOADS"),
	}
	for _, d := range strings.Split(getenv("EXPOSEHUB_TUNNEL_DOMAINS"), ",") {
		if d = strings.TrimSpace(d); d != "" {
			cfg.TunnelDomains = append(cfg.TunnelDomains, d)
		}
	}
	for _, v := range strings.Split(getenv("EXPOSEHUB_TRUSTED_PROXIES"), ",") {
		if v = strings.TrimSpace(v); v == "" {
			continue
		}
		prefix, err := parsePrefix(v)
		if err != nil {
			return Config{}, fmt.Errorf("EXPOSEHUB_TRUSTED_PROXIES: %w", err)
		}
		cfg.TrustedProxies = append(cfg.TrustedProxies, prefix)
	}
	for _, pair := range strings.Split(getenv("EXPOSEHUB_CUSTOM_DOMAIN_HEADERS"), ",") {
		if pair = strings.TrimSpace(pair); pair == "" {
			continue
		}
		name, value, ok := strings.Cut(pair, "=")
		if name = strings.TrimSpace(name); !ok || name == "" {
			return Config{}, fmt.Errorf("EXPOSEHUB_CUSTOM_DOMAIN_HEADERS: %q is not Name=Value", pair)
		}
		if cfg.CustomDomainHeaders == nil {
			cfg.CustomDomainHeaders = map[string]string{}
		}
		cfg.CustomDomainHeaders[name] = strings.TrimSpace(value)
	}
	if v := getenv("EXPOSEHUB_TLS_ASK_TUNNEL_NAMES"); v != "" {
		b, err := strconv.ParseBool(v)
		if err != nil {
			return Config{}, fmt.Errorf("EXPOSEHUB_TLS_ASK_TUNNEL_NAMES: %q is not true or false", v)
		}
		cfg.TLSAskTunnelNames = b
	}
	if v := getenv("EXPOSEHUB_TLS_ASK_NAME_LIMIT"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 {
			return Config{}, fmt.Errorf("EXPOSEHUB_TLS_ASK_NAME_LIMIT: %q is not a positive number", v)
		}
		cfg.TLSAskNameLimit = n
	}
	if v := getenv("EXPOSEHUB_TLS_ASK_TOTAL_LIMIT"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 0 {
			return Config{}, fmt.Errorf("EXPOSEHUB_TLS_ASK_TOTAL_LIMIT: %q is not a number of names, or 0 for no limit", v)
		}
		cfg.TLSAskTotalLimit = n
		if n == 0 {
			cfg.TLSAskTotalLimit = -1
		}
	}
	if v := getenv("EXPOSEHUB_TLS_ASK_NAME_WINDOW"); v != "" {
		d, err := time.ParseDuration(v)
		if err != nil || d <= 0 {
			return Config{}, fmt.Errorf("EXPOSEHUB_TLS_ASK_NAME_WINDOW: %q is not a positive duration", v)
		}
		cfg.TLSAskNameWindow = d
	}
	if v := getenv("EXPOSEHUB_GRACE_PERIOD"); v != "" {
		d, err := time.ParseDuration(v)
		if err != nil {
			return Config{}, fmt.Errorf("EXPOSEHUB_GRACE_PERIOD: %w", err)
		}
		cfg.GracePeriod = d
	}
	return cfg, cfg.normalize()
}

func (c *Config) normalize() error {
	if c.Listen == "" {
		c.Listen = ":8080"
	}
	c.AgentHost = normalizeHost(c.AgentHost)
	if c.AgentHost == "" {
		return errors.New("agent host is required (EXPOSEHUB_AGENT_HOST)")
	}
	if len(c.TunnelDomains) == 0 {
		return errors.New("at least one tunnel domain is required (EXPOSEHUB_TUNNEL_DOMAINS)")
	}
	for i, d := range c.TunnelDomains {
		c.TunnelDomains[i] = normalizeHost(d)
	}
	if (c.TLSCertFile == "") != (c.TLSKeyFile == "") {
		return errors.New("TLS needs both a certificate file and a key file")
	}
	if c.PublicScheme == "" {
		c.PublicScheme = "http"
		if c.TLSCertFile != "" {
			c.PublicScheme = "https"
		}
	}
	if c.PublicScheme != "http" && c.PublicScheme != "https" {
		return fmt.Errorf("public scheme must be http or https, got %q", c.PublicScheme)
	}
	if c.PublicPort == "" {
		_, port, err := net.SplitHostPort(c.Listen)
		if err != nil {
			return fmt.Errorf("listen address %q: %w", c.Listen, err)
		}
		c.PublicPort = port
	}
	if c.GracePeriod <= 0 {
		c.GracePeriod = time.Minute
	}
	if c.TLSAskNameLimit <= 0 {
		c.TLSAskNameLimit = 10
	}
	if c.TLSAskTotalLimit == 0 {
		c.TLSAskTotalLimit = 40
	}
	if c.TLSAskTotalLimit > 0 && c.TLSAskNameLimit >= c.TLSAskTotalLimit {
		// One account could otherwise take every certificate there is.
		return fmt.Errorf("the per-team certificate budget (%d, EXPOSEHUB_TLS_ASK_NAME_LIMIT) must be below the install's (%d, EXPOSEHUB_TLS_ASK_TOTAL_LIMIT)", c.TLSAskNameLimit, c.TLSAskTotalLimit)
	}
	if c.TLSAskNameWindow <= 0 {
		c.TLSAskNameWindow = 7 * 24 * time.Hour
	}
	return nil
}

func (c *Config) hasDomain(domain string) bool {
	for _, d := range c.TunnelDomains {
		if d == domain {
			return true
		}
	}
	return false
}

// trusts reports whether remoteAddr, a host:port, belongs to a trusted proxy.
func (c *Config) trusts(remoteAddr string) bool {
	ap, err := netip.ParseAddrPort(remoteAddr)
	if err != nil {
		return false
	}
	return c.trustsAddr(ap.Addr().Unmap())
}

// trustsAddr reports whether addr belongs to a trusted proxy.
func (c *Config) trustsAddr(addr netip.Addr) bool {
	for _, p := range c.TrustedProxies {
		if p.Contains(addr) {
			return true
		}
	}
	return false
}

// parsePrefix accepts a CIDR or a single address.
func parsePrefix(s string) (netip.Prefix, error) {
	if p, err := netip.ParsePrefix(s); err == nil {
		return p.Masked(), nil
	}
	addr, err := netip.ParseAddr(s)
	if err != nil {
		return netip.Prefix{}, fmt.Errorf("%q is not an address or a CIDR", s)
	}
	return netip.PrefixFrom(addr, addr.BitLen()), nil
}

func (c *Config) publicURL(host string) string {
	u := c.PublicScheme + "://" + host
	standard := (c.PublicScheme == "http" && c.PublicPort == "80") ||
		(c.PublicScheme == "https" && c.PublicPort == "443")
	if !standard {
		u += ":" + c.PublicPort
	}
	return u
}

// normalizeHost lowercases a Host header value and strips the port and a
// trailing dot, so it can be compared with configured hostnames.
func normalizeHost(hostport string) string {
	host := strings.TrimSpace(hostport)
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}
	return strings.ToLower(strings.TrimSuffix(host, "."))
}

var labelPattern = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)

func validLabel(s string) bool { return labelPattern.MatchString(s) }
