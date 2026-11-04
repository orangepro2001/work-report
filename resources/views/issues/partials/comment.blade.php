@php
    /** Minimal, safe Markdown subset: **bold**, *italic*, `code`, [text](url), @mention. */
    $html = e($comment->body);

    $html = preg_replace('/`([^`\n]+)`/u', '<code>$1</code>', $html);
    $html = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?![\*\w])/u', '<em>$1</em>', $html);
    // Only http(s) links survive: no javascript:, no data:.
    $html = preg_replace(
        '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u',
        '<a href="$2" target="_blank" rel="noopener noreferrer nofollow">$1</a>',
        $html,
    );
    // @이름 — Korean names, so the class is Unicode-aware.
    $html = preg_replace('/@([\p{L}\p{N}_.-]{2,20})/u', '<span class="mention">@$1</span>', $html);
@endphp

<article class="comment" data-comment-id="{{ $comment->id }}">
    <span class="avatar" style="background: {{ $comment->author?->color ?? '#98a2b3' }}"
          title="{{ $comment->author?->name }}">{{ $comment->author?->initials() ?? '?' }}</span>
    <div>
        <div class="comment__head">
            <span class="comment__author">{{ $comment->author?->name ?? '—' }}</span>
            <time class="tiny muted" datetime="{{ $comment->created_at->toIso8601String() }}"
                  title="{{ $comment->created_at->format('Y-m-d H:i') }}">
                {{ $comment->created_at->diffForHumans(short: true) }}
            </time>
        </div>
        <div class="comment__body">{!! $html !!}</div>
    </div>
</article>
