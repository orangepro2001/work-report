@props(['member' => null, 'size' => null, 'title' => null])

{{--
    One avatar, two sources: the picture the person uploaded to the authorization
    center (members.picture_url, refreshed on every sign-in), or their initial on
    their own colour.

    The image covers the initial rather than replacing it, so a URL that stops working
    (picture deleted at the SSO, SSO down) degrades to the initial with no JavaScript
    and no broken-image icon.
--}}
<span class="avatar {{ $size ? 'avatar--' . $size : '' }}"
      @if ($member?->color) style="background: {{ $member->color }}" @endif
      @if ($title ?? $member?->name) title="{{ $title ?? $member->name }}" @endif>
    <span class="avatar__text">{{ $member?->initials() ?? '?' }}</span>
    @if ($member?->picture_url)
        <img src="{{ $member->picture_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">
    @endif
</span>
