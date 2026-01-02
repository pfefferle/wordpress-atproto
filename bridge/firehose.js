#!/usr/bin/env node
/**
 * AT Protocol Firehose Bridge for WordPress
 *
 * Connects your WordPress PDS to the AT Protocol relay network.
 *
 * Usage:
 *   npm install ws cbor
 *   node firehose.js --url=https://notiz.blog --port=8080
 *
 * Uberspace:
 *   uberspace web backend set /xrpc/com.atproto.sync.subscribeRepos --http --port 8080
 */

const { WebSocketServer } = require('ws');
const cbor = require('cbor');

// Parse arguments
const args = process.argv.slice(2).reduce((acc, arg) => {
  const [key, value] = arg.replace('--', '').split('=');
  acc[key] = value;
  return acc;
}, {});

const WP_URL = args.url || process.env.WP_URL;
const PORT = parseInt(args.port || process.env.PORT || '8080');

if (!WP_URL) {
  console.error('Usage: node firehose.js --url=https://your-site.com [--port=8080]');
  process.exit(1);
}

// Get DID from WordPress
async function getDid() {
  const res = await fetch(`${WP_URL}/xrpc/com.atproto.server.describeServer`);
  const data = await res.json();
  return data.did;
}

// Fetch records from WordPress
async function fetchRecords(collection, cursor = '') {
  const params = new URLSearchParams({
    repo: await getDid(),
    collection,
    limit: '100',
  });
  if (cursor) params.set('cursor', cursor);

  const res = await fetch(`${WP_URL}/xrpc/com.atproto.repo.listRecords?${params}`);
  return res.json();
}

// Encode varint
function encodeVarint(n) {
  const bytes = [];
  while (n >= 0x80) {
    bytes.push((n & 0x7f) | 0x80);
    n >>>= 7;
  }
  bytes.push(n);
  return Buffer.from(bytes);
}

// Build commit frame
function buildCommitFrame(did, record, seq) {
  const header = { op: 1, t: '#commit' };

  const body = {
    seq,
    rebase: false,
    tooBig: true,  // Signal relay to fetch full repo via getRepo
    repo: did,
    rev: record.uri.split('/').pop(),
    since: null,
    blocks: new Uint8Array(0),
    ops: [{
      action: 'create',
      path: record.uri.split('/').slice(-2).join('/'),
      cid: record.cid ? { '/': record.cid } : null,
    }],
    blobs: [],
    time: new Date().toISOString(),
  };

  if (record.cid) {
    body.commit = { '/': record.cid };
  }

  const headerBytes = cbor.encode(header);
  const bodyBytes = cbor.encode(body);

  return Buffer.concat([
    encodeVarint(headerBytes.length),
    headerBytes,
    bodyBytes,
  ]);
}

// Main
async function main() {
  const did = await getDid();
  console.log(`AT Protocol Firehose Bridge`);
  console.log(`WordPress: ${WP_URL}`);
  console.log(`DID: ${did}`);
  console.log(`Port: ${PORT}`);
  console.log('');

  const wss = new WebSocketServer({ port: PORT, host: '0.0.0.0' });

  wss.on('connection', async (ws, req) => {
    const url = new URL(req.url, `http://localhost`);
    const cursor = parseInt(url.searchParams.get('cursor') || '0');

    console.log(`[${new Date().toISOString()}] Connection, cursor: ${cursor}`);

    try {
      // Send profile
      const profileRes = await fetch(
        `${WP_URL}/xrpc/com.atproto.repo.getRecord?repo=${did}&collection=app.bsky.actor.profile&rkey=self`
      );
      if (profileRes.ok) {
        const profile = await profileRes.json();
        ws.send(buildCommitFrame(did, profile, 1));
      }

      // Send posts
      let seq = cursor || 2;
      let nextCursor = '';

      do {
        const data = await fetchRecords('app.bsky.feed.post', nextCursor);

        for (const record of data.records || []) {
          ws.send(buildCommitFrame(did, record, seq++));
        }

        nextCursor = data.cursor || '';
      } while (nextCursor);

      console.log(`[${new Date().toISOString()}] Sent ${seq - 1} commits`);

      // Keep connection alive with periodic pings
      const pingInterval = setInterval(() => {
        if (ws.readyState === ws.OPEN) {
          ws.ping();
        }
      }, 30000);

      ws.on('close', () => {
        clearInterval(pingInterval);
        console.log(`[${new Date().toISOString()}] Disconnected`);
      });

    } catch (err) {
      console.error(`Error: ${err.message}`);
      ws.close();
    }
  });

  console.log(`WebSocket server running on ws://0.0.0.0:${PORT}`);
}

main().catch(console.error);
