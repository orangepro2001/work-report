@php
    // Rendered on the way out by the application's single Markdown renderer
    // (app/Support/Markdown.php): GitHub dialect, raw HTML stripped, unsafe links
    // dropped, @mentions highlighted at the document level.
    $html = \App\Support\Markdown::toHtml($comment->body);
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
        <div class="comment__body markdown">{!! $html !!}</div>
    </div>
</article>
