# AT Protocol Firehose Bridge

A minimal Node.js WebSocket server that bridges your WordPress AT Protocol PDS to the relay network.

## Why?

WordPress/PHP cannot maintain persistent WebSocket connections. This small Node.js bridge:
- Connects to your WordPress via its REST API
- Serves the `subscribeRepos` WebSocket endpoint
- Allows relays to index your content

## Setup

```bash
cd bridge
npm install
node firehose.js --url=https://your-site.com --port=8080
```

## Uberspace

```bash
# 1. Install
cd ~/html/wp-content/plugins/wordpress-atproto/bridge
npm install

# 2. Create daemon
cat > ~/etc/services.d/atproto-bridge.ini << EOF
[program:atproto-bridge]
command=node %(ENV_HOME)s/html/wp-content/plugins/wordpress-atproto/bridge/firehose.js --url=https://your-site.com --port=8080
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

Any host that supports Node.js and WebSocket:
- **Vercel**: Not supported (no WebSocket)
- **Cloudflare Workers**: Use Durable Objects
- **Fly.io**: Works great
- **Railway**: Works great
- **VPS**: Works with pm2 or systemd

## Environment Variables

Instead of CLI arguments:
```bash
export WP_URL=https://your-site.com
export PORT=8080
node firehose.js
```
