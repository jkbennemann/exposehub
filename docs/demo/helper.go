//go:build ignore

// Command helper plays the two parts of the README demo that are not
// exposehub: the local service on port 3000 and the visitors of its public
// address. record.sh builds it; nothing else uses it.
//
//	helper shop              a local service on 127.0.0.1:3000
//	helper visit SERVER HOST waits until an agent runs (its inspector
//	                         listens), then sends a few requests for HOST
//	                         to the tunnel server at SERVER
package main

import (
	"fmt"
	"net"
	"net/http"
	"os"
	"strings"
	"time"
)

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: helper shop | helper visit SERVER HOST")
		os.Exit(2)
	}
	switch os.Args[1] {
	case "shop":
		shop()
	case "visit":
		visit(os.Args[2], os.Args[3])
	}
}

func shop() {
	err := http.ListenAndServe("127.0.0.1:3000", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(time.Duration(2+len(r.URL.Path)%7) * time.Millisecond)
		fmt.Fprintln(w, "ok")
	}))
	fmt.Fprintln(os.Stderr, err)
	os.Exit(1)
}

// visit sends what a browser and a webhook sender would. The tunnel server
// listens on a local port in the demo, so the public name goes in Host.
func visit(server, host string) {
	send := func(method, path string) {
		req, err := http.NewRequest(method, server+path, strings.NewReader(""))
		if err != nil {
			return
		}
		req.Host = host
		if resp, err := http.DefaultClient.Do(req); err == nil {
			resp.Body.Close()
		}
	}
	// A request through the tunnel would show up in the recording, so the
	// agent's inspector port tells when it has started.
	for {
		conn, err := net.Dial("tcp", "127.0.0.1:4040")
		if err == nil {
			conn.Close()
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	time.Sleep(1800 * time.Millisecond)
	for _, r := range []struct {
		method, path string
		pause        time.Duration
	}{
		{"GET", "/", 250 * time.Millisecond},
		{"GET", "/assets/app.css", 150 * time.Millisecond},
		{"GET", "/assets/app.js", 900 * time.Millisecond},
		{"POST", "/webhooks/stripe", 800 * time.Millisecond},
		{"GET", "/cart", 600 * time.Millisecond},
		{"GET", "/products/42", 700 * time.Millisecond},
		{"POST", "/webhooks/stripe", 0},
	} {
		send(r.method, r.path)
		time.Sleep(r.pause)
	}
}
