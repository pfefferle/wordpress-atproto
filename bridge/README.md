# AT Protocol Firehose Bridge

A Go WebSocket server that bridges your WordPress AT Protocol PDS to the relay network.

## Why?

WordPress/PHP cannot maintain persistent WebSocket connections. This Go bridge:
- Polls your WordPress REST API for new repository events
- Serves the `com.atproto.sync.subscribeRepos` WebSocket endpoint
- Broadcasts DAG-CBOR frames to connected relays
- Supports cursor-based replay from a 1000-event ring buffer

## Prerequisites

1. Create a **WordPress Application Password** for the bridge:
   - Go to **Users → Profile → Application Passwords**
   - Enter a name (e.g., "AT Protocol Firehose") and click "Add New"
   - Copy the generated password

## Build

```bash
cd bridge
go build -o firehose .
```

## Usage

```bash
./firehose \
  --wp-url=https://your-site.com \
  --username=admin \
  --app-password="XXXX XXXX XXXX XXXX XXXX XXXX" \
  --port=8080 \
  --poll-interval=5s
```

## Uberspace

```bash
# 1. Build
cd ~/html/wp-content/plugins/wordpress-atproto/bridge
go build -o firehose .

# 2. Create daemon
cat > ~/etc/services.d/atproto-bridge.ini << EOF
[program:atproto-bridge]
command=%(ENV_HOME)s/html/wp-content/plugins/wordpress-atproto/bridge/firehose --wp-url=https://your-site.com --username=admin --app-password=XXXX-XXXX-XXXX-XXXX-XXXX-XXXX --port=8080
startsecs=60
EOF

# 3. Start
supervisorctl reread
supervisorctl update
supervisorctl start atproto-bridge

# 4. Proxy WebSocket
uberspace web backend set /xrpc/com.atproto.sync.subscribeRepos --http --port 8080
```

## Other Hosts

Any host that supports Go binaries and WebSocket:
- **Fly.io**: Works great
- **Railway**: Works great
- **VPS**: Works with systemd
- **Vercel**: Not supported (no WebSocket)
- **Cloudflare Workers**: Use Durable Objects

## Environment Variables

Instead of CLI arguments:
```bash
export WP_URL=https://your-site.com
export WP_USERNAME=admin
export WP_APP_PASSWORD="XXXX XXXX XXXX XXXX XXXX XXXX"
./firehose --port=8080
```

## Verification

```bash
# Check events endpoint
curl -u admin:XXXX https://your-site.com/wp-json/atproto/v1/firehose/events?since=0

# Connect to WebSocket
websocat ws://localhost:8080/xrpc/com.atproto.sync.subscribeRepos

# Connect with cursor replay
websocat "ws://localhost:8080/xrpc/com.atproto.sync.subscribeRepos?cursor=5"
```
