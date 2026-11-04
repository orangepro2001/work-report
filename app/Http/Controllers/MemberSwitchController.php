<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\SessionActorResolver;
use Illuminate\Http\Request;

/**
 * The member switcher.
 *
 * Stands in for sign-in until the SSO project is integrated (docs/auth-seam.md).
 * It writes the same session value the resolver reads, so replacing this with a
 * real login changes one route, not the data model.
 */
class MemberSwitchController extends Controller
{
    public function __invoke(Request $request, SessionActorResolver $resolver)
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
        ]);

        $resolver->switchTo($data['member_id']);

        $member = Member::findOrFail($data['member_id']);

        return back()->with('status', "{$member->name}(으)로 전환했습니다.");
    }
}
