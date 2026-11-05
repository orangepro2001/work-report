<?php

namespace App\Support;

use App\Models\Document;
use App\Models\Member;

/**
 * The short-lived pass a browser needs to join a document's Yjs room
 * (docs/documents.md).
 *
 * Signed with HMAC-SHA256 over a base64url payload, verified by the sync service
 * (`deploy/collab/auth.cjs`) which shares nothing with this application except the
 * secret. The payload names one room and an expiry, so a token leaking into a chat
 * log opens one document until it expires and nothing else.
 */
final class CollabToken
{
    public const TTL = 900;

    public static function issue(Document $document, Member $member, ?int $ttl = null): string
    {
        $payload = ['room' => $document->slug, 'sub' => $member->id, 'exp' => time() + ($ttl ?? self::TTL)];
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return $body.'.'.self::sign($body);
    }

    /**
     * @return array{room: string, sub: int, exp: int}|null
     */
    public static function verify(?string $token): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $dot = strrpos($token, '.');

        if ($dot === false || $dot === 0) {
            return null;
        }

        [$body, $signature] = [substr($token, 0, $dot), substr($token, $dot + 1)];

        if (! hash_equals(self::sign($body), $signature)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);

        if (! is_array($payload) || ! isset($payload['room'], $payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return ['room' => (string) $payload['room'], 'sub' => (int) ($payload['sub'] ?? 0), 'exp' => (int) $payload['exp']];
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('workreport.collab.secret'));
    }
}
