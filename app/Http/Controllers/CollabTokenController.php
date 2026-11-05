<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\Document;
use App\Support\CollabToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hands the browser a pass for one document's real-time room.
 *
 * Authorization happens *here*, once, with the same rules as the editor: the
 * WebSocket service only checks the signature (deploy/collab/auth.cjs). A viewer
 * gets no token, so "can read the page" never turns into "can type into it".
 */
final class CollabTokenController extends Controller
{
    public function __construct(private readonly ActorResolver $actors) {}

    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $me = $this->actors->require();

        abort_unless($document->isVisibleTo($me), 403);

        if (! $document->isEditableBy($me)) {
            return response()->json(['ok' => true, 'editable' => false]);
        }

        // Rendered for the browser, so it carries the mount prefix ForceMountPath sets.
        $base = rtrim(url((string) config('workreport.collab.url')), '/');

        return response()->json([
            'ok' => true,
            'editable' => true,
            'room' => $document->slug,
            'url' => $base.'/'.$document->slug,
            'token' => CollabToken::issue($document, $me),
            'expires_in' => CollabToken::TTL,
        ]);
    }
}
