<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\Issue;
use App\Models\IssueTodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An issue's checklist, as data (app/Models/IssueTodo.php).
 *
 * Every action answers with the whole list plus its progress, because that is what the
 * panel shows: adding, ticking, renaming, reordering and deleting are all one request
 * and one re-render, with no page reload and no Markdown editing.
 */
class IssueTodoController extends Controller
{
    public function __construct(private readonly ActorResolver $actors) {}

    public function store(Request $request, Issue $issue): JsonResponse
    {
        $me = $this->actors->require();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'assignee_id' => ['nullable', 'integer', 'exists:members,id'],
            'due_on' => ['nullable', 'date'],
        ]);

        $issue->todos()->create([
            'title' => trim($data['title']),
            'position' => (int) $issue->todos()->max('position') + 1,
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'created_by' => $me->id,
        ]);

        return $this->state($issue);
    }

    public function update(Request $request, IssueTodo $todo): JsonResponse
    {
        $this->actors->require();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:300'],
            'done' => ['sometimes', 'boolean'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:members,id'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        $todo->fill($data)->save();

        return $this->state($todo->issue);
    }

    public function destroy(IssueTodo $todo): JsonResponse
    {
        $this->actors->require();

        $issue = $todo->issue;
        $todo->delete();

        return $this->state($issue);
    }

    /** One row up or down; the list is small, so positions are renumbered in full. */
    public function move(Request $request, IssueTodo $todo): JsonResponse
    {
        $this->actors->require();

        $direction = $request->string('direction')->toString() === 'up' ? -1 : 1;
        $issue = $todo->issue;

        $ordered = $issue->todos()->get()->values();
        $index = $ordered->search(fn (IssueTodo $row) => $row->id === $todo->id);
        $target = $index + $direction;

        if ($index === false || $target < 0 || $target >= $ordered->count()) {
            return $this->state($issue);
        }

        $ordered[$index] = $ordered[$target];
        $ordered[$target] = $todo;

        foreach ($ordered->values() as $position => $row) {
            $row->forceFill(['position' => $position + 1])->save();
        }

        return $this->state($issue->fresh());
    }

    private function state(Issue $issue): JsonResponse
    {
        $todos = $issue->todos()->with('assignee:id,name')->get();
        $done = $todos->where('done', true)->count();

        return response()->json([
            'todos' => $todos->map(fn (IssueTodo $todo) => $todo->payload())->values(),
            'total' => $todos->count(),
            'done' => $done,
            'percent' => $todos->count() === 0 ? 0 : (int) round($done / $todos->count() * 100),
        ]);
    }
}
