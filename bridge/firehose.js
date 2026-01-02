#!/usr/bin/env node
/**
 * Minimal AT Protocol Firehose Bridge
 *
 * Just announces the repo and keeps connection alive.
 * Relay fetches actual data via getRepo.
 *
 * Usage: node firehose.js --url=https://notiz.blog --port=8080
 */

const { WebSocketServer } = require('ws');
const cbor = require('cbor');

const args = Object.fromEntries(
  process.argv.slice(2).map(a => a.replace('--', '').split('='))
);

const WP_URL = args.url || process.env.WP_URL;
const PORT = parseInt(args.port || process.env.PORT || '8080');

if (!WP_URL) {
  console.log('Usage: node firehose.js --url=https://your-site.com --port=8080');
  process.exit(1);
}

// Get DID from URL
const host = new URL(WP_URL).host;
const did = `did:web:${host}`;

console.log(`Firehose Bridge`);
console.log(`URL: ${WP_URL}`);
console.log(`DID: ${did}`);
console.log(`Port: ${PORT}\n`);

// Varint encoding
function varint(n) {
  const bytes = [];
  while (n >= 0x80) {
    bytes.push((n & 0x7f) | 0x80);
    n >>>= 7;
  }
  bytes.push(n);
  return Buffer.from(bytes);
}

// Build frame
function buildFrame(type, body) {
  const header = cbor.encode({ op: 1, t: type });
  const payload = cbor.encode(body);
  return Buffer.concat([varint(header.length), header, payload]);
}

let seq = Date.now();

const wss = new WebSocketServer({ port: PORT, host: '0.0.0.0' });

wss.on('connection', (ws, req) => {
  const clientIp = req.socket.remoteAddress;
  console.log(`[${new Date().toISOString()}] Connected: ${clientIp}`);

  // Send #identity event to announce repo
  const identity = buildFrame('#identity', {
    seq: seq++,
    did: did,
    time: new Date().toISOString(),
    handle: host,
  });
  ws.send(identity);
  console.log(`[${new Date().toISOString()}] Sent #identity for ${did}`);

  // Keep alive
  const interval = setInterval(() => {
    if (ws.readyState === ws.OPEN) {
      ws.ping();
    }
  }, 30000);

  ws.on('close', () => {
    clearInterval(interval);
    console.log(`[${new Date().toISOString()}] Disconnected: ${clientIp}`);
  });

  ws.on('error', (err) => {
    console.log(`[${new Date().toISOString()}] Error: ${err.message}`);
  });
});

console.log(`Listening on ws://0.0.0.0:${PORT}`);
