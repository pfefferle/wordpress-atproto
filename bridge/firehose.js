#!/usr/bin/env node
/**
 * AT Protocol Firehose Bridge
 *
 * WebSocket server for relays + HTTP endpoint for receiving events from PHP.
 *
 * Usage: node firehose.js --url=https://example.com --port=8080 [--secret=xxx]
 *
 * Endpoints:
 *   ws://host:port/              - WebSocket for relays (subscribeRepos)
 *   POST http://host:port/event  - Receive events from PHP
 */

const http = require('http');
const { WebSocketServer } = require('ws');

const args = Object.fromEntries(
  process.argv.slice(2).map(a => a.replace('--', '').split('='))
);

const WP_URL = args.url || process.env.WP_URL;
const PORT = parseInt(args.port || process.env.PORT || '8080');
const SECRET = args.secret || process.env.FIREHOSE_SECRET || '';

if (!WP_URL) {
  console.log('Usage: node firehose.js --url=https://your-site.com --port=8080 [--secret=xxx]');
  process.exit(1);
}

const host = new URL(WP_URL).host;
const did = `did:web:${host}`;

console.log('Firehose Bridge');
console.log(`URL: ${WP_URL}`);
console.log(`DID: ${did}`);
console.log(`Port: ${PORT}`);
console.log(`Secret: ${SECRET ? '(configured)' : '(none)'}\n`);

/**
 * Broadcast binary data to all connected WebSocket clients.
 */
function broadcast(data) {
  let sent = 0;
  wss.clients.forEach(client => {
    if (client.readyState === 1) { // OPEN
      client.send(data);
      sent++;
    }
  });
  return sent;
}

/**
 * HTTP server for receiving events from PHP.
 */
const server = http.createServer((req, res) => {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  if (req.method === 'POST' && req.url === '/event') {
    // Check secret if configured
    if (SECRET) {
      const auth = req.headers['authorization'] || '';
      if (auth !== `Bearer ${SECRET}`) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Unauthorized' }));
        return;
      }
    }

    let body = [];
    req.on('data', chunk => body.push(chunk));
    req.on('end', () => {
      try {
        const data = Buffer.concat(body);
        const sent = broadcast(data);

        console.log(`[${new Date().toISOString()}] Broadcast ${data.length} bytes to ${sent} clients`);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, clients: sent }));
      } catch (err) {
        console.error(`[${new Date().toISOString()}] Error: ${err.message}`);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: err.message }));
      }
    });
    return;
  }

  // Health check / info
  if (req.method === 'GET' && (req.url === '/' || req.url === '/health')) {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      service: 'atproto-firehose-bridge',
      did: did,
      clients: wss.clients.size
    }));
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Not found' }));
});

/**
 * WebSocket server for relay connections.
 */
const wss = new WebSocketServer({ server });

wss.on('connection', (ws, req) => {
  const clientIp = req.headers['x-forwarded-for'] || req.socket.remoteAddress;
  console.log(`[${new Date().toISOString()}] WebSocket connected: ${clientIp} (total: ${wss.clients.size})`);

  // Keep alive with pings
  const interval = setInterval(() => {
    if (ws.readyState === 1) {
      ws.ping();
    }
  }, 25000);

  ws.on('close', () => {
    clearInterval(interval);
    console.log(`[${new Date().toISOString()}] WebSocket disconnected: ${clientIp} (total: ${wss.clients.size})`);
  });

  ws.on('error', (err) => {
    console.log(`[${new Date().toISOString()}] WebSocket error: ${err.message}`);
  });
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`WebSocket: ws://0.0.0.0:${PORT}/`);
  console.log(`HTTP POST: http://0.0.0.0:${PORT}/event`);
});
