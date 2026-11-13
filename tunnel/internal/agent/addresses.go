package agent

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"

	"github.com/jkbennemann/exposehub/tunnel/internal/proto"
)

// FetchAddresses asks the server which addresses tunnels opened with token
// may take: subdomains of its tunnel domains, and the domains the token's
// team has verified.
func FetchAddresses(ctx context.Context, client *http.Client, server, token string) (proto.Addresses, error) {
	var out proto.Addresses
	base, err := HTTPBase(server)
	if err != nil {
		return out, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, base+proto.AddressesPath, nil)
	if err != nil {
		return out, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	resp, err := client.Do(req)
	if err != nil {
		return out, fmt.Errorf("could not reach %s: %w", base, err)
	}
	defer resp.Body.Close()
	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotFound:
		return out, fmt.Errorf("%s is older than exposehub 0.4 and cannot list its addresses", base)
	case http.StatusUnauthorized:
		return out, errors.New("the tunnel server refused the agent token; log in again with: exposehub login")
	default:
		return out, fmt.Errorf("%s answered %s", base, resp.Status)
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&out); err != nil {
		return out, fmt.Errorf("%s sent addresses that cannot be read: %w", base, err)
	}
	return out, nil
}
