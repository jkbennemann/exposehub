package server

import "testing"

func TestConfigReadsTheInternalListener(t *testing.T) {
	env := map[string]string{
		"EXPOSEHUB_AGENT_HOST":           "connect.tunnel.example",
		"EXPOSEHUB_TUNNEL_DOMAINS":       "tunnel.example",
		"EXPOSEHUB_INTERNAL_LISTEN":      ":9090",
		"EXPOSEHUB_TLS_ASK_TUNNEL_NAMES": "true",
	}
	cfg, err := ConfigFromEnv(func(k string) string { return env[k] })
	if err != nil {
		t.Fatal(err)
	}
	if cfg.InternalListen != ":9090" || !cfg.TLSAskTunnelNames {
		t.Errorf("got %q, %v", cfg.InternalListen, cfg.TLSAskTunnelNames)
	}

	env["EXPOSEHUB_TLS_ASK_TUNNEL_NAMES"] = "sometimes"
	if _, err := ConfigFromEnv(func(k string) string { return env[k] }); err == nil {
		t.Error("an unreadable EXPOSEHUB_TLS_ASK_TUNNEL_NAMES was accepted")
	}
}

func TestConfigReadsTheCertificateBudgets(t *testing.T) {
	env := map[string]string{
		"EXPOSEHUB_AGENT_HOST":     "connect.tunnel.example",
		"EXPOSEHUB_TUNNEL_DOMAINS": "tunnel.example",
	}
	read := func() (Config, error) {
		cfg, err := ConfigFromEnv(func(k string) string { return env[k] })
		if err == nil {
			err = cfg.normalize() // as New does once more
		}
		return cfg, err
	}
	cfg, err := read()
	if err != nil || cfg.TLSAskNameLimit != 10 || cfg.TLSAskTotalLimit != 40 {
		t.Errorf("defaults: per team %d, total %d, err %v; want 10 and 40", cfg.TLSAskNameLimit, cfg.TLSAskTotalLimit, err)
	}

	env["EXPOSEHUB_TLS_ASK_TOTAL_LIMIT"] = "0"
	if cfg, err := read(); err != nil || cfg.TLSAskTotalLimit >= 0 {
		t.Errorf("0: total %d, err %v; want no install-wide limit", cfg.TLSAskTotalLimit, err)
	}

	env["EXPOSEHUB_TLS_ASK_TOTAL_LIMIT"] = "10"
	if _, err := read(); err == nil {
		t.Error("a per-team budget as large as the install's was accepted")
	}
}
