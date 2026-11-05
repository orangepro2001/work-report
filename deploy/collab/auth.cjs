/*
 * Token check for the Yjs service.
 *
 * The token is issued by the application (App\Support\CollabToken) after it has
 * decided that this member may edit this document: base64url(payload) + "." +
 * hex(HMAC-SHA256(payload, COLLAB_SECRET)). The payload names the room and the
 * expiry, so a leaked token opens one document until it expires — nothing else.
 */
'use strict';

const crypto = require('crypto');

const SECRET = process.env.COLLAB_SECRET || '';

function verify(token) {
    if (typeof token !== 'string' || token === '' || SECRET === '') {
        return null;
    }

    const dot = token.lastIndexOf('.');
    if (dot <= 0) {
        return null;
    }

    const body = token.slice(0, dot);
    const signature = token.slice(dot + 1);

    const expected = crypto.createHmac('sha256', SECRET).update(body).digest('hex');
    const a = Buffer.from(signature, 'utf8');
    const b = Buffer.from(expected, 'utf8');

    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
        return null;
    }

    let claims;
    try {
        claims = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
    } catch (e) {
        return null;
    }

    if (!claims || typeof claims.room !== 'string' || typeof claims.exp !== 'number') {
        return null;
    }

    if (claims.exp * 1000 < Date.now()) {
        return null;
    }

    return claims;
}

module.exports = { verify };
