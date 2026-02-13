package main

import (
	"encoding/base32"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/fxamacker/cbor/v2"
	"github.com/gorilla/websocket"
)

// Event represents a firehose event from WordPress.
type Event struct {
	Type   string        `json:"$type"`
	Seq    int64         `json:"seq"`
	Time   string        `json:"time"`
	Repo   string        `json:"repo,omitempty"`
	Rev    string        `json:"rev,omitempty"`
	Commit *CIDLink      `json:"commit,omitempty"`
	TooBig bool          `json:"tooBig,omitempty"`
	Blocks *BytesWrapper `json:"blocks,omitempty"`
	Ops    []Op          `json:"ops,omitempty"`
	DID    string        `json:"did,omitempty"`
	Handle string        `json:"handle,omitempty"`
	Active *bool         `json:"active,omitempty"`
	Status string        `json:"status,omitempty"`
}

// CIDLink represents a CID reference.
type CIDLink struct {
	Link string `json:"$link"`
}

// BytesWrapper represents AT Protocol bytes.
type BytesWrapper struct {
	Bytes string `json:"$bytes"`
}

// Op represents a commit operation.
type Op struct {
	Action string   `json:"action"`
	Path   string   `json:"path"`
	CID    *CIDLink `json:"cid,omitempty"`
}

// PollResponse is the JSON response from the WordPress endpoint.
type PollResponse struct {
	Events []Event `json:"events"`
	Seq    int64   `json:"seq"`
}

// --- CBOR frame encoding ---

// cborHeader is the DAG-CBOR frame header.
type cborHeader struct {
	Op int    `cbor:"op"`
	T  string `cbor:"t"`
}

// cborCIDTag wraps a CID bytes value as CBOR tag 42.
type cborCIDTag = cbor.Tag

// cborCommitEvent is the CBOR body for a #commit event.
type cborCommitEvent struct {
	Seq    int64       `cbor:"seq"`
	Rebase bool        `cbor:"rebase"`
	TooBig bool        `cbor:"tooBig"`
	Repo   string      `cbor:"repo"`
	Commit cbor.Tag    `cbor:"commit"`
	Rev    string      `cbor:"rev"`
	Since  *string     `cbor:"since"`
	Blocks []byte      `cbor:"blocks"`
	Ops    []cborOp    `cbor:"ops"`
	Blobs  []cbor.Tag  `cbor:"blobs"`
	Time   string      `cbor:"time"`
}

// cborOp is the CBOR body for an operation within a commit.
type cborOp struct {
	Action string    `cbor:"action"`
	Path   string    `cbor:"path"`
	CID    *cbor.Tag `cbor:"cid,omitempty"`
}

// cborIdentityEvent is the CBOR body for an #identity event.
type cborIdentityEvent struct {
	Seq    int64  `cbor:"seq"`
	DID    string `cbor:"did"`
	Handle string `cbor:"handle"`
	Time   string `cbor:"time"`
}

// cborAccountEvent is the CBOR body for an #account event.
type cborAccountEvent struct {
	Seq    int64  `cbor:"seq"`
	DID    string `cbor:"did"`
	Active bool   `cbor:"active"`
	Status string `cbor:"status,omitempty"`
	Time   string `cbor:"time"`
}

// cidToTag wraps a CID string (multibase base32lower) as CBOR tag 42.
// DAG-CBOR tag 42 content is: 0x00 (identity multibase) + raw CID bytes.
func cidToTag(link string) cbor.Tag {
	if len(link) > 1 && link[0] == 'b' {
		// Base32lower multibase: strip 'b' prefix and decode.
		enc := base32.StdEncoding.WithPadding(base32.NoPadding)
		raw, err := enc.DecodeString(strings.ToUpper(link[1:]))
		if err == nil {
			return cbor.Tag{
				Number:  42,
				Content: append([]byte{0x00}, raw...),
			}
		}
	}

	// Fallback for empty or unparseable CIDs.
	return cbor.Tag{
		Number:  42,
		Content: []byte{0x00},
	}
}

// buildFrame builds a DAG-CBOR WebSocket frame.
// Per the AT Protocol event stream spec, each WebSocket binary message
// contains two concatenated CBOR objects: header + body (no length prefix).
func buildFrame(eventType string, body interface{}) ([]byte, error) {
	em, err := cbor.CanonicalEncOptions().EncMode()
	if err != nil {
		return nil, err
	}

	header := cborHeader{Op: 1, T: eventType}
	headerBytes, err := em.Marshal(header)
	if err != nil {
		return nil, fmt.Errorf("encode header: %w", err)
	}

	bodyBytes, err := em.Marshal(body)
	if err != nil {
		return nil, fmt.Errorf("encode body: %w", err)
	}

	var frame []byte
	frame = append(frame, headerBytes...)
	frame = append(frame, bodyBytes...)
	return frame, nil
}

// eventToFrame converts a JSON event to a DAG-CBOR frame.
func eventToFrame(ev Event) ([]byte, error) {
	switch ev.Type {
	case "#commit":
		ops := make([]cborOp, len(ev.Ops))
		for i, op := range ev.Ops {
			ops[i] = cborOp{
				Action: op.Action,
				Path:   op.Path,
			}
			if op.CID != nil {
				tag := cidToTag(op.CID.Link)
				ops[i].CID = &tag
			}
		}

		commitLink := ""
		if ev.Commit != nil {
			commitLink = ev.Commit.Link
		}

		body := cborCommitEvent{
			Seq:    ev.Seq,
			Rebase: false,
			TooBig: ev.TooBig,
			Repo:   ev.Repo,
			Commit: cidToTag(commitLink),
			Rev:    ev.Rev,
			Since:  nil,
			Blocks: []byte{},
			Ops:    ops,
			Blobs:  []cbor.Tag{},
			Time:   ev.Time,
		}
		return buildFrame("#commit", body)

	case "#identity":
		body := cborIdentityEvent{
			Seq:    ev.Seq,
			DID:    ev.DID,
			Handle: ev.Handle,
			Time:   ev.Time,
		}
		return buildFrame("#identity", body)

	case "#account":
		active := false
		if ev.Active != nil {
			active = *ev.Active
		}
		body := cborAccountEvent{
			Seq:    ev.Seq,
			DID:    ev.DID,
			Active: active,
			Status: ev.Status,
			Time:   ev.Time,
		}
		return buildFrame("#account", body)

	default:
		return nil, fmt.Errorf("unknown event type: %s", ev.Type)
	}
}

// --- Ring buffer ---

type ringBuffer struct {
	mu     sync.RWMutex
	events []Event
	frames [][]byte
	size   int
}

func newRingBuffer(size int) *ringBuffer {
	return &ringBuffer{
		events: make([]Event, 0, size),
		frames: make([][]byte, 0, size),
		size:   size,
	}
}

func (rb *ringBuffer) add(ev Event, frame []byte) {
	rb.mu.Lock()
	defer rb.mu.Unlock()

	if len(rb.events) >= rb.size {
		rb.events = rb.events[1:]
		rb.frames = rb.frames[1:]
	}
	rb.events = append(rb.events, ev)
	rb.frames = append(rb.frames, frame)
}

func (rb *ringBuffer) since(seq int64) [][]byte {
	rb.mu.RLock()
	defer rb.mu.RUnlock()

	var result [][]byte
	for i, ev := range rb.events {
		if ev.Seq > seq {
			result = append(result, rb.frames[i:]...)
			break
		}
	}
	return result
}

// --- Hub: fan-out broadcast ---

type client struct {
	conn   *websocket.Conn
	send   chan []byte
	closed chan struct{}
}

type hub struct {
	mu      sync.RWMutex
	clients map[*client]struct{}
}

func newHub() *hub {
	return &hub{
		clients: make(map[*client]struct{}),
	}
}

func (h *hub) add(c *client) {
	h.mu.Lock()
	h.clients[c] = struct{}{}
	h.mu.Unlock()
}

func (h *hub) remove(c *client) {
	h.mu.Lock()
	delete(h.clients, c)
	h.mu.Unlock()
}

func (h *hub) broadcast(frame []byte) {
	h.mu.RLock()
	defer h.mu.RUnlock()

	for c := range h.clients {
		select {
		case c.send <- frame:
		default:
			// Client too slow, drop.
		}
	}
}

func (h *hub) count() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.clients)
}

// --- Main ---

func main() {
	wpURL := flag.String("wp-url", os.Getenv("WP_URL"), "WordPress site URL")
	port := flag.Int("port", 8080, "WebSocket server port")
	username := flag.String("username", os.Getenv("WP_USERNAME"), "WordPress username")
	appPassword := flag.String("app-password", os.Getenv("WP_APP_PASSWORD"), "WordPress application password")
	restBase := flag.String("rest-base", "wp-json", "WordPress REST API base path")
	pollInterval := flag.Duration("poll-interval", 5*time.Second, "Poll interval")
	flag.Parse()

	if *wpURL == "" || *username == "" || *appPassword == "" {
		fmt.Fprintln(os.Stderr, "Usage: firehose --wp-url=https://your-site.com --username=admin --app-password=XXXX [--port=8080] [--poll-interval=5s]")
		os.Exit(1)
	}

	log.Printf("AT Protocol Firehose Bridge")
	log.Printf("WordPress: %s", *wpURL)
	log.Printf("Username: %s", *username)
	log.Printf("Port: %d", *port)
	log.Printf("Poll interval: %s", *pollInterval)

	h := newHub()
	rb := newRingBuffer(1000)
	var lastSeq int64

	upgrader := websocket.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}

	// Poller goroutine.
	go func() {
		endpoint := fmt.Sprintf("%s/%s/atproto/v1/firehose/events", *wpURL, *restBase)
		httpClient := &http.Client{Timeout: 30 * time.Second}

		for {
			url := fmt.Sprintf("%s?since=%d&limit=100", endpoint, lastSeq)

			req, err := http.NewRequest("GET", url, nil)
			if err != nil {
				log.Printf("Error creating request: %v", err)
				time.Sleep(*pollInterval)
				continue
			}

			req.SetBasicAuth(*username, *appPassword)

			resp, err := httpClient.Do(req)
			if err != nil {
				log.Printf("Poll error: %v", err)
				time.Sleep(*pollInterval)
				continue
			}

			body, err := io.ReadAll(resp.Body)
			resp.Body.Close()

			if resp.StatusCode != 200 {
				log.Printf("Poll HTTP %d: %s", resp.StatusCode, string(body))
				time.Sleep(*pollInterval)
				continue
			}

			if err != nil {
				log.Printf("Read error: %v", err)
				time.Sleep(*pollInterval)
				continue
			}

			var result PollResponse
			if err := json.Unmarshal(body, &result); err != nil {
				log.Printf("JSON decode error: %v", err)
				time.Sleep(*pollInterval)
				continue
			}

			for _, ev := range result.Events {
				frame, err := eventToFrame(ev)
				if err != nil {
					log.Printf("Frame encode error (seq=%d): %v", ev.Seq, err)
					continue
				}

				rb.add(ev, frame)
				h.broadcast(frame)
				lastSeq = ev.Seq
			}

			if result.Seq > lastSeq {
				lastSeq = result.Seq
			}

			time.Sleep(*pollInterval)
		}
	}()

	// WebSocket handler.
	http.HandleFunc("/xrpc/com.atproto.sync.subscribeRepos", func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Printf("Upgrade error: %v", err)
			return
		}

		log.Printf("Client connected: %s (total: %d)", r.RemoteAddr, h.count()+1)

		c := &client{
			conn:   conn,
			send:   make(chan []byte, 256),
			closed: make(chan struct{}),
		}

		h.add(c)

		// Replay buffered events. If a cursor is given, replay from that
		// point; otherwise replay everything so new subscribers (like the
		// relay) immediately learn about the current repo state.
		var cursorSeq int64
		if cursor := r.URL.Query().Get("cursor"); cursor != "" {
			fmt.Sscanf(cursor, "%d", &cursorSeq)
		}
		for _, frame := range rb.since(cursorSeq) {
			if err := conn.WriteMessage(websocket.BinaryMessage, frame); err != nil {
				log.Printf("Replay write error: %v", err)
				h.remove(c)
				conn.Close()
				return
			}
		}

		// Writer goroutine.
		go func() {
			ticker := time.NewTicker(30 * time.Second)
			defer ticker.Stop()
			defer conn.Close()
			defer h.remove(c)

			for {
				select {
				case frame, ok := <-c.send:
					if !ok {
						return
					}
					if err := conn.WriteMessage(websocket.BinaryMessage, frame); err != nil {
						log.Printf("Write error: %v", err)
						return
					}
				case <-ticker.C:
					if err := conn.WriteMessage(websocket.PingMessage, nil); err != nil {
						return
					}
				case <-c.closed:
					return
				}
			}
		}()

		// Reader goroutine (drain reads, detect close).
		for {
			_, _, err := conn.ReadMessage()
			if err != nil {
				close(c.closed)
				log.Printf("Client disconnected: %s (total: %d)", r.RemoteAddr, h.count()-1)
				return
			}
		}
	})

	addr := fmt.Sprintf("0.0.0.0:%d", *port)
	log.Printf("Listening on ws://%s", addr)
	log.Fatal(http.ListenAndServe(addr, nil))
}
