// An HTTP/1.1 server written with the Go standard library, independent of PHP and of Guzzle. The wire tests send requests
// to it and read what it received: the length and the SHA-256 of the body, and the framing headers.
//
// Routes:
//
//	POST /echo            reads the whole body and answers a JSON object (length, sha256, contentLength, transferEncoding)
//	POST /cut?after=N     reads N bytes of the body, then closes the connection without an answer
//	GET  /__count?path=P  answers the number of requests seen for the path P
//
// Build: CGO_ENABLED=0 go build -o server server.go
// Run:   server           prints "LISTENING 127.0.0.1:<port>" and serves until it is killed.
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"sync"
)

var (
	mutex  sync.Mutex
	counts = map[string]int{}
)

func count(path string) {
	mutex.Lock()
	defer mutex.Unlock()
	counts[path]++
}

func echo(w http.ResponseWriter, r *http.Request) {
	count(r.URL.Path)
	hash := sha256.New()
	length, err := io.Copy(hash, r.Body)
	answer := map[string]any{
		"length":           length,
		"sha256":           hex.EncodeToString(hash.Sum(nil)),
		"contentLength":    r.ContentLength,
		"transferEncoding": r.TransferEncoding,
		"error":            fmt.Sprint(err),
	}
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(answer)
}

func cut(w http.ResponseWriter, r *http.Request) {
	count(r.URL.Path)
	after, _ := strconv.ParseInt(r.URL.Query().Get("after"), 10, 64)
	_, _ = io.CopyN(io.Discard, r.Body, after)
	hijacker, ok := w.(http.Hijacker)
	if !ok {
		return
	}
	connection, _, err := hijacker.Hijack()
	if err == nil {
		_ = connection.Close()
	}
}

func counter(w http.ResponseWriter, r *http.Request) {
	mutex.Lock()
	defer mutex.Unlock()
	_, _ = fmt.Fprint(w, counts[r.URL.Query().Get("path")])
}

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/echo", echo)
	mux.HandleFunc("/cut", cut)
	mux.HandleFunc("/__count", counter)

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		panic(err)
	}
	fmt.Println("LISTENING", listener.Addr().String())
	_ = http.Serve(listener, mux)
}
