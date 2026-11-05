<?php

namespace App\Support\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * A `@handle` reference, as a real node rather than raw HTML.
 *
 * It has to be a node: CommonMark's `html_input` setting applies to every
 * HtmlInline it renders, so injecting the span as raw HTML made mentions vanish
 * the moment raw HTML was stripped from comments.
 */
final class Mention extends AbstractInline
{
    public function __construct(public readonly string $handle)
    {
        parent::__construct();
    }
}
