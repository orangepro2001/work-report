<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\Document;
use App\Models\DocumentPresence;
use App\Models\DocumentRevision;
use App\Models\DocumentShare;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The document library — internal documents that would otherwise live in someone's
 * drive (docs/documents.md).
 *
 * Concurrency is optimistic: the editor sends the `revision` it started from, and a
 * stale number is answered with 409 plus the newest text, never by overwriting the
 * other person. Every save also writes a `document_revisions` row, so a bad edit is
 * one click away from being undone.
 */
class DocumentsController extends Controller
{
    public function __construct(private readonly ActorResolver $actors) {}

    public function index(Request $request): View
    {
        $me = $this->actors->require();

        $documents = Document::query()
            ->with(['owner:id,name,color,picture_url', 'shares.member:id,name'])
            ->where(fn ($q) => $q->where('visibility', Document::VISIBILITY_TEAM)
                ->orWhere('owner_id', $me->id)
                ->orWhereHas('shares', fn ($s) => $s->where('member_id', $me->id)))
            ->orderByDesc('updated_at')
            ->get();

        return view('docs.index', [
            'documents' => $documents,
            'me' => $me,
            'q' => trim((string) $request->query('q', '')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $this->actors->require();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'visibility' => ['nullable', Rule::in([Document::VISIBILITY_TEAM, Document::VISIBILITY_PRIVATE])],
        ]);

        $document = DB::transaction(function () use ($data, $me) {
            $document = Document::create([
                'slug' => Document::uniqueSlug($data['title']),
                'title' => $data['title'],
                'body' => '',
                'visibility' => $data['visibility'] ?? Document::VISIBILITY_TEAM,
                'owner_id' => $me->id,
                'revision' => 1,
            ]);

            DocumentRevision::create([
                'document_id' => $document->id,
                'revision' => 1,
                'title' => $document->title,
                'body' => '',
                'editor_id' => $me->id,
                'created_at' => now(),
            ]);

            return $document;
        });

        return redirect()->route('docs.show', $document)->with('status', __('ui.docs.created'));
    }

    public function show(Request $request, Document $document): View
    {
        $me = $this->actors->require();

        abort_unless($document->isVisibleTo($me), 403);

        $this->touchPresence($document, $me);

        $editable = $document->isEditableBy($me);

        return view('docs.editor', [
            'document' => $document->load(['owner:id,name,color,picture_url', 'shares.member:id,name,color,picture_url']),
            'me' => $me,
            // Read mode: what a viewer sees, and what anybody can switch to instead of
            // reading raw Markdown in the editor.
            'read' => $request->boolean('read') || ! $editable,
            'members' => Member::active()->orderBy('position')->get(['id', 'name', 'color']),
            'readers' => $this->readers($document),
            'revisions' => $document->revisions()->with('editor:id,name')->limit(30)->get(),
            'editable' => $editable,
        ]);
    }

    public function update(Request $request, Document $document): JsonResponse
    {
        $me = $this->actors->require();

        abort_unless($document->isEditableBy($me), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'visibility' => ['sometimes', Rule::enum(Document::class, 'visibility')->only([Document::VISIBILITY_TEAM, Document::VISIBILITY_PRIVATE])],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        if ((int) $data['revision'] !== $document->revision) {
            // Someone saved while this browser was typing. Hand back the truth and let
            // the person decide; overwriting is the one thing that must not happen.
            return response()->json([
                'ok' => false,
                'conflict' => true,
                'revision' => $document->revision,
                'title' => $document->title,
                'body' => $document->body,
                'edited_by' => $document->revisions()->with('editor:id,name')->first()?->editor?->name,
                'message' => __('ui.docs.conflict'),
            ], 409);
        }

        $changed = false;
        foreach (['title', 'body', 'visibility'] as $field) {
            if (array_key_exists($field, $data) && (string) $document->{$field} !== (string) ($data[$field] ?? '')) {
                $changed = true;
            }
        }

        if (! $changed) {
            return response()->json(['ok' => true, 'revision' => $document->revision, 'unchanged' => true]);
        }

        $revision = DB::transaction(function () use ($document, $data, $me) {
            $next = $document->revision + 1;

            $document->fill(array_filter($data, fn ($v, $k) => $k !== 'revision', ARRAY_FILTER_USE_BOTH));
            $document->revision = $next;
            $document->save();

            DocumentRevision::create([
                'document_id' => $document->id,
                'revision' => $next,
                'title' => $document->title,
                'body' => (string) $document->body,
                'editor_id' => $me->id,
                'created_at' => now(),
            ]);

            return $next;
        });

        return response()->json([
            'ok' => true,
            'revision' => $revision,
            'updated_at' => $document->updated_at?->toIso8601String(),
        ]);
    }

    /** Poll: has anything changed, and who is looking at this right now? */
    public function state(Document $document): JsonResponse
    {
        $me = $this->actors->require();

        abort_unless($document->isVisibleTo($me), 403);

        $this->touchPresence($document, $me);

        $latest = $document->revisions()->with('editor:id,name')->first();

        return response()->json([
            'revision' => $document->revision,
            'title' => $document->title,
            'body' => $document->body,
            'updated_at' => $document->updated_at?->toIso8601String(),
            'edited_by' => $latest?->editor?->name,
            'readers' => $this->readers($document)->map(fn (Member $m) => [
                'id' => $m->id, 'name' => $m->name, 'color' => $m->color,
                'me' => $m->id === $me->id,
            ])->values(),
        ]);
    }

    public function restore(Document $document, int $revision): RedirectResponse
    {
        $me = $this->actors->require();

        abort_unless($document->isEditableBy($me), 403);

        $source = $document->revisions()->where('revision', $revision)->firstOrFail();

        DB::transaction(function () use ($document, $source, $me) {
            $next = $document->revision + 1;

            $document->update(['title' => $source->title, 'body' => $source->body, 'revision' => $next]);

            DocumentRevision::create([
                'document_id' => $document->id,
                'revision' => $next,
                'title' => $source->title,
                'body' => $source->body,
                'editor_id' => $me->id,
                'created_at' => now(),
            ]);
        });

        return back()->with('status', __('ui.docs.restored', ['revision' => $revision]));
    }

    /** Sharing a private document with one more person (owner only). */
    public function share(Request $request, Document $document): RedirectResponse
    {
        $me = $this->actors->require();

        abort_unless($document->owner_id === $me->id, 403);

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'role' => ['nullable', Rule::in([DocumentShare::ROLE_EDITOR, DocumentShare::ROLE_VIEWER])],
        ]);

        DocumentShare::updateOrCreate(
            ['document_id' => $document->id, 'member_id' => $data['member_id']],
            ['role' => $data['role'] ?? DocumentShare::ROLE_EDITOR],
        );

        return back()->with('status', __('ui.docs.shared'));
    }

    public function unshare(Document $document, Member $member): RedirectResponse
    {
        $me = $this->actors->require();

        abort_unless($document->owner_id === $me->id, 403);

        DocumentShare::where('document_id', $document->id)->where('member_id', $member->id)->delete();

        return back()->with('status', __('ui.docs.unshared'));
    }

    /**
     * @return Collection<int,Member>
     */
    private function readers(Document $document)
    {
        return Member::query()
            ->whereIn('id', $document->presence()
                ->where('seen_at', '>=', now()->subSeconds(DocumentPresence::TTL))
                ->pluck('member_id'))
            ->orderBy('position')
            ->get(['id', 'name', 'color']);
    }

    private function touchPresence(Document $document, Member $me): void
    {
        DocumentPresence::updateOrCreate(
            ['document_id' => $document->id, 'member_id' => $me->id],
            ['seen_at' => now()],
        );
    }
}
