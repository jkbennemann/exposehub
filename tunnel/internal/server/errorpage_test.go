package server

import "testing"

func TestReopenCommandNamesTheAddress(t *testing.T) {
	s := &Server{cfg: Config{TunnelDomains: []string{"tunnel.example", "team.example"}}}
	cases := map[string]string{
		"demo.tunnel.example":     "exposehub http <port> --subdomain demo",
		"demo.team.example":       "exposehub http <port> --subdomain demo --domain team.example",
		"dev.customer.de":         "exposehub http <port> --hostname dev.customer.de",
		"a.b.tunnel.example":      "exposehub http <port> --hostname a.b.tunnel.example",
		"tunnel.example.evil.com": "exposehub http <port> --hostname tunnel.example.evil.com",
	}
	for host, want := range cases {
		if got := s.reopenCommand(host); got != want {
			t.Errorf("reopenCommand(%q) = %q, want %q", host, got, want)
		}
	}
}
