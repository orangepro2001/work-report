<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Local image storage for Markdown (docs/documents.md).
 *
 * Nothing leaves this host: the bytes land on the `local` disk inside the
 * container's storage volume, and are handed back through this controller — never
 * through a public directory, because an image in a private document must not be
 * readable by guessing its URL.
 */
class UploadController extends Controller
{
    private const MAX_KB = 12 * 1024;

    /** Content types, not extensions: `mimes:` would accept a renamed .php. */
    private const MIMES = 'image/jpeg,image/png,image/webp,image/gif,image/avif';

    public function __construct(private readonly ActorResolver $actors) {}

    public function store(Request $request): JsonResponse
    {
        $me = $this->actors->require();

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:'.self::MIMES, 'max:'.self::MAX_KB],
            // Optional context so the file is attached to what it belongs to.
            'document' => ['nullable', 'integer', 'exists:documents,id'],
            'issue' => ['nullable', 'integer', 'exists:issues,id'],
        ]);

        $file = $request->file('file');
        $name = Str::ulid()->toBase32().'.'.Str::lower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $path = 'uploads/'.now()->format('Y/m').'/'.$name;

        Storage::disk('local')->putFileAs('uploads/'.now()->format('Y/m'), $file, $name);

        $attachment = Attachment::create([
            'attachable_type' => $request->filled('document') ? Document::class : ($request->filled('issue') ? Issue::class : null),
            'attachable_id' => $request->integer('document') ?: ($request->integer('issue') ?: null),
            'uploader_id' => $me->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        $url = route('uploads.show', [
            'attachment' => $attachment->id,
            'name' => Str::slug(pathinfo($attachment->original_name, PATHINFO_FILENAME)) ?: 'file',
        ], absolute: false).'.'.Str::lower(pathinfo($attachment->original_name, PATHINFO_EXTENSION));

        return response()->json([
            'id' => $attachment->id,
            'url' => $url,
            'name' => $attachment->original_name,
            'markdown' => '!['.$attachment->original_name.']('.$url.')',
        ], 201);
    }

    public function show(Attachment $attachment, ?string $name = null): StreamedResponse
    {
        $me = $this->actors->require();

        abort_unless($this->mayRead($attachment, $me), 403);
        abort_unless($attachment->exists(), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            [
                // Content is immutable (ULID filename), so it may be cached hard.
                'Cache-Control' => 'private, max-age=31536000, immutable',
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function mayRead(Attachment $attachment, Member $me): bool
    {
        $owner = $attachment->attachable;

        if ($owner instanceof Document) {
            return $owner->isVisibleTo($me);
        }

        // Board images are internal to the team; an unattached upload belongs to
        // whoever put it there until something claims it.
        if ($owner instanceof Issue) {
            return true;
        }

        return $attachment->uploader_id === $me->id;
    }
}
