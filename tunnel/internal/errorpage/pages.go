package errorpage

import (
	"fmt"
	"net/http"
)

// The visitor of a tunnel address is often not the person who opened it: a
// client, a phone, a payment provider. The texts speak to them first and give
// the tunnel's owner a hint where one helps.

// Reconnecting is the answer while a tunnel's agent is away but its address
// is still reserved for it.
func Reconnecting() Page {
	return Page{
		Status: http.StatusBadGateway,
		Cable:  Gap,
		Title:  "This tunnel is reconnecting",
		Text:   "The computer behind this address lost its connection for a moment. This page tries again every 5 seconds and shows the site as soon as it is back.",
		Retry:  5,
		Plain:  "exposehub: the tunnel for this address is reconnecting",
	}
}

// NoTunnel is the answer for an address no tunnel is open at. command is
// what its owner would type to open it again, or empty.
func NoTunnel(command string) Page {
	p := Page{
		Status: http.StatusNotFound,
		Cable:  Loose,
		Title:  "Nothing is connected here",
		Text:   "No tunnel is open at this address right now. If someone sent you this link, their tunnel has probably stopped. Ask them to start it again.",
		Plain:  "exposehub: no tunnel is connected at this address",
	}
	if command != "" {
		p.Hint = "If it is your tunnel, start it again with the port of your service:"
		p.Command = command
	}
	return p
}

// TunnelDidNotAnswer is the answer when the tunnel server could not pass a
// request on to the agent.
func TunnelDidNotAnswer() Page {
	return Page{
		Status: http.StatusBadGateway,
		Cable:  Fault,
		Title:  "The tunnel did not answer",
		Text:   "The tunnel is open, but this request did not get through to the computer behind it. Reload the page in a moment.",
		Plain:  "exposehub: the tunnel did not answer",
	}
}

// PasswordRequired is the answer to a visitor without the tunnel's password.
// Browsers show it when the sign-in prompt is cancelled.
func PasswordRequired() Page {
	return Page{
		Status: http.StatusUnauthorized,
		Cable:  Locked,
		Title:  "This tunnel is password protected",
		Text:   "Reload the page and enter the name and password you were given. The person who opened the tunnel can tell you what they are.",
		Plain:  "exposehub: this tunnel is password protected",
	}
}

// NetworkDenied is the answer to a visitor outside the networks a tunnel is
// limited to. It names their address, which is what they need to sort it
// out: connect the VPN, or ask for their network to be added.
func NetworkDenied(addr string) Page {
	return Page{
		Status: http.StatusForbidden,
		Cable:  Locked,
		Title:  "This tunnel is limited to certain networks",
		Text:   "It only answers requests from the networks its team allowed, such as a company VPN. Connect to that network and reload, or ask the person who shared the link to allow yours.",
		Detail: "Your address: " + addr,
		Plain:  "exposehub: this tunnel only accepts requests from certain networks; your address is " + addr,
	}
}

// LocalServiceDown is the agent's answer when the service it forwards to
// did not answer, typically because it is not running. Anyone on the
// internet can get this page, so it does not name the local address or the
// error: they describe the developer's machine and network, and the
// address can hold values from their environment. The agent shows both in
// its own terminal and inspector instead.
func LocalServiceDown() Page {
	return Page{
		Status: http.StatusBadGateway,
		Cable:  Fault,
		Title:  "The site behind this tunnel is not answering",
		Text:   "The tunnel works, but nothing answered on the computer behind it. The service may be stopped or still starting. This page tries again every 5 seconds.",
		Detail: "If this is your tunnel, the terminal running exposehub and its inspector show why.",
		Retry:  5,
		Plain:  "exposehub: the local service behind this tunnel did not answer",
	}
}

// UnknownTunnel is the agent's answer for a tunnel it no longer serves.
func UnknownTunnel() Page {
	return Page{
		Status: http.StatusBadGateway,
		Cable:  Fault,
		Title:  "This tunnel is not ready",
		Text:   "The computer behind this address does not serve it at the moment. Reload the page in a moment.",
		Plain:  "exposehub: the agent does not know this tunnel",
	}
}

// DemoNotice is what a browser gets before the first page of a demo tunnel.
// Anyone can open such a tunnel without an account, so the visitor is told
// that the tunnel server does not know who is behind the address. service is
// the host of the install, continueURL where the button leads.
func DemoNotice(service, continueURL string) Page {
	return Page{
		Status:    http.StatusOK,
		Cable:     Open,
		Title:     "This is a demo tunnel",
		Text:      "Someone opened this address with the demo of " + service + ", without an account. The page behind it is theirs, and " + service + " does not know who they are. Do not enter passwords or payment details unless you know who runs it.",
		Action:    "Continue to the page",
		ActionURL: continueURL,
		Footer:    "Demo tunnel",
		Plain:     "exposehub: this is a demo tunnel, opened by someone without an account; do not enter passwords or payment details unless you know who runs it. Open " + continueURL + " on this address to continue",
	}
}

// DemoLimit is the answer once a demo tunnel has had more requests than it
// gets a minute.
func DemoLimit(perMinute int) Page {
	return Page{
		Status: http.StatusTooManyRequests,
		Cable:  Locked,
		Title:  "This demo tunnel is at its limit",
		Text:   fmt.Sprintf("A demo tunnel answers up to %d requests a minute, and this one has had more. Wait a moment and reload the page.", perMinute),
		Plain:  fmt.Sprintf("exposehub: this demo tunnel is limited to %d requests a minute; try again in a moment", perMinute),
	}
}
