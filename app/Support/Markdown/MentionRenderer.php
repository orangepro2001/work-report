<?php

namespace App\Support\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Renders a Mention node. `HtmlElement` escapes the handle, so a name can never
 * smuggle markup into the page.
 */
final class MentionRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof Mention) {
            throw new \InvalidArgumentException('MentionRenderer can only render Mention nodes.');
        }

        return new HtmlElement('span', ['class' => 'mention'], '@'.$node->handle);
    }
}
