<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The people list — the board's own membership, not the SSO's accounts.
 *
 * A member exists here because work is attributed to them: an assignee, a reporter, a
 * comment's author. Accounts are the authorization center's business; this page is about
 * the names that appear in the workbook.
 *
 * Deactivating is the normal way to retire somebody: they leave every picker and the
 * 담당자 요약 sheet, while their history keeps its attribution. Deleting is offered only
 * when nothing references them, because most foreign keys here are `on delete set null`
 * and history would quietly lose its author.
 */
class MemberController extends Controller
{
    public function __construct(private readonly ActorResolver $actors) {}

    public function index(Request $request): View
    {
        $me = $this->actors->require();

        $members = Member::query()
            ->withCount(['assignedIssues'])
            ->orderByDesc('active')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return view('members.index', [
            'members' => $members,
            'me' => $me,
            'colors' => ['#2F5597', '#C65911', '#375623', '#7030A0', '#7F7F7F', '#1F7A7A', '#B3261E'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->actors->require();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('members', 'key')],
            'title' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);

        // The key is what an SSO first sign-in adopts (`docs/auth-seam.md`): naming it
        // after the person's username means the account links itself when they arrive.
        $key = $data['key'] ?? Str::slug($data['name']);

        Member::create([
            'key' => $this->uniqueKey($key),
            'name' => trim($data['name']),
            'title' => $data['title'] ?? null,
            'department' => $data['department'] ?? config('workreport.department'),
            'color' => $data['color'] ?? '#2F5597',
            'position' => (int) Member::max('position') + 1,
            'active' => true,
            'is_external' => false,
        ]);

        return back()->with('status', __('ui.members.created'));
    }

    public function update(Request $request, Member $member): RedirectResponse
    {
        $this->actors->require();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'title' => ['sometimes', 'nullable', 'string', 'max:60'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'active' => ['sometimes', 'boolean'],
            'is_external' => ['sometimes', 'boolean'],
        ]);

        $member->fill($data)->save();

        return back()->with('status', __('ui.members.updated'));
    }

    public function destroy(Member $member): RedirectResponse
    {
        $me = $this->actors->require();

        abort_if($member->id === $me->id, 403, __('ui.members.not_self'));

        if ($member->referenceCount() > 0) {
            return back()->withErrors([
                'member' => __('ui.members.has_history', ['name' => $member->name]),
            ]);
        }

        $member->delete();

        return back()->with('status', __('ui.members.deleted', ['name' => $member->name]));
    }

    private function uniqueKey(string $base): string
    {
        $key = $base !== '' ? $base : 'member';

        for ($i = 2; Member::where('key', $key)->exists(); $i++) {
            $key = $base.'-'.$i;
        }

        return $key;
    }
}
