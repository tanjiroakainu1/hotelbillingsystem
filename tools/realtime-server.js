/*
 * Hotel Billing System - realtime WebSocket server (no npm packages required).
 *
 *   node tools/realtime-server.js          (or double-click tools/start-realtime.bat)
 *
 * Browsers connect to ws://<host>:8081. After any successful write the PHP API calls
 * POST http://127.0.0.1:8081/notify (loopback only), and this server broadcasts a
 * small "changed" message. The message carries no data; each browser re-fetches
 * through the normal, authenticated API.
 */
const http = require('http');
const crypto = require('crypto');

const PORT = Number(process.env.REALTIME_PORT || 8081);
const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const clients = new Set();

function frame(opcode, payload = Buffer.alloc(0)) {
  const len = payload.length;
  let header;
  if (len < 126) {
    header = Buffer.from([0x80 | opcode, len]);
  } else if (len < 65536) {
    header = Buffer.from([0x80 | opcode, 126, len >> 8, len & 0xff]);
  } else {
    header = Buffer.alloc(10);
    header[0] = 0x80 | opcode;
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(len), 2);
  }
  return Buffer.concat([header, payload]);
}

function broadcast(message) {
  const data = frame(0x1, Buffer.from(message));
  for (const socket of clients) {
    if (!socket.destroyed) socket.write(data);
  }
}

const server = http.createServer((req, res) => {
  const remote = req.socket.remoteAddress || '';
  const loopback = remote === '127.0.0.1' || remote === '::1' || remote === '::ffff:127.0.0.1';
  if (req.method === 'POST' && req.url.startsWith('/notify')) {
    if (!loopback) {
      res.writeHead(403).end();
      return;
    }
    broadcast(JSON.stringify({ type: 'changed', at: Date.now() }));
    res.writeHead(204).end();
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end(`Hotel realtime server OK - ${clients.size} client(s) connected\n`);
});

server.on('upgrade', (req, socket) => {
  const key = req.headers['sec-websocket-key'];
  if (!key || (req.headers.upgrade || '').toLowerCase() !== 'websocket') {
    socket.destroy();
    return;
  }
  const accept = crypto.createHash('sha1').update(key + GUID).digest('base64');
  socket.write(
    'HTTP/1.1 101 Switching Protocols\r\n' +
      'Upgrade: websocket\r\nConnection: Upgrade\r\n' +
      `Sec-WebSocket-Accept: ${accept}\r\n\r\n`
  );
  clients.add(socket);
  socket.write(frame(0x1, Buffer.from(JSON.stringify({ type: 'hello' }))));

  let buffer = Buffer.alloc(0);
  socket.on('data', (chunk) => {
    buffer = Buffer.concat([buffer, chunk]);
    while (buffer.length >= 2) {
      const opcode = buffer[0] & 0x0f;
      const masked = (buffer[1] & 0x80) !== 0;
      let len = buffer[1] & 0x7f;
      let offset = 2;
      if (len === 126) {
        if (buffer.length < 4) return;
        len = buffer.readUInt16BE(2);
        offset = 4;
      } else if (len === 127) {
        if (buffer.length < 10) return;
        len = Number(buffer.readBigUInt64BE(2));
        offset = 10;
      }
      const total = offset + (masked ? 4 : 0) + len;
      if (len > 65536) return socket.destroy();
      if (buffer.length < total) return;
      const payload = Buffer.from(buffer.subarray(offset + (masked ? 4 : 0), total));
      if (masked) {
        const mask = buffer.subarray(offset, offset + 4);
        for (let i = 0; i < payload.length; i += 1) payload[i] ^= mask[i % 4];
      }
      buffer = buffer.subarray(total);
      if (opcode === 0x8) {
        socket.end(frame(0x8));
        return;
      }
      if (opcode === 0x9) socket.write(frame(0xa, payload));
    }
  });
  const drop = () => clients.delete(socket);
  socket.on('close', drop);
  socket.on('error', drop);
});

// Keep idle connections alive through proxies and detect dead ones.
setInterval(() => {
  for (const socket of clients) {
    if (!socket.destroyed) socket.write(frame(0x9));
  }
}, 25000);

server.listen(PORT, () => {
  console.log(`Realtime server listening on port ${PORT}`);
});
