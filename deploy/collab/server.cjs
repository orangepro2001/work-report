/*
 * The Yjs sync service (docs/documents.md).
 *
 * One room per document slug. There is no anonymous access: the connection URL
 * carries a token from the application, and the room in the token must match the
 * room in the path. Nothing is written to disk — the document text is persisted by
 * the application's own revision save, so a restart loses only in-flight keystrokes
 * of a document nobody has saved yet.
 */
'use strict';

const http = require('http');
const { WebSocketServer } = require('ws');
const { setupWSConnection } = require('y-websocket/bin/utils');
const { verify } = require('./auth.cjs');

const port = Number(process.env.PORT || 1234);
const host = process.env.HOST || '127.0.0.1';

const server = http.createServer((req, res) => {
    // A plain HTTP probe, so the container has something to health-check.
    res.writeHead(req.url === '/healthz' ? 200 : 404, { 'Content-Type': 'text/plain' });
    res.end(req.url === '/healthz' ? 'ok' : 'collab');
});

const wss = new WebSocketServer({ server });

wss.on('connection', (conn, req) => {
    const url = new URL(req.url, `http://${host}`);
    const room = decodeURIComponent(url.pathname.replace(/^\/+/, ''));
    const claims = verify(url.searchParams.get('token') || '');

    if (!claims || claims.room !== room) {
        conn.close(4001, 'unauthorized');
        return;
    }

    conn.memberId = claims.sub || null;
    setupWSConnection(conn, req, { docName: room, gc: true });
});

server.listen(port, host, () => {
    console.log(`collab listening on ${host}:${port}`);
});
