<?php

namespace App\Support\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Splits text nodes on `@handle` and swaps the handle for an inline span.
 *
 * Korean names are the common case, so the pattern is Unicode-aware and a mention
 * may be a single character. Code, links and existing raw HTML are left alone.
 */
final class MentionsProcessor
{
    private const PATTERN = '/@([\p{L}\p{N}_.-]{1,20})/u';

    private const SKIP = [Code::class, Link::class, HtmlBlock::class];

    public function __invoke(DocumentParsedEvent $event): void
    {
        $walker = $event->getDocument()->walker();

        while ($node = $walker->next()) {
            if (! $node->isEntering() || ! $node->getNode() instanceof Text) {
                continue;
            }

            /** @var Text $text */
            $text = $node->getNode();

            if ($this->insideSkippedAncestor($text)) {
                continue;
            }

            $this->rewrite($text);
        }
    }

    private function insideSkippedAncestor(Node $node): bool
    {
        for ($parent = $node->parent(); $parent !== null; $parent = $parent->parent()) {
            foreach (self::SKIP as $class) {
                if ($parent instanceof $class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function rewrite(Text $text): void
    {
        $value = $text->getLiteral();

        if (preg_match_all(self::PATTERN, $value, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        $cursor = 0;
        $replacements = [];

        foreach ($matches[0] as $index => [$whole, $offset]) {
            if ($offset > $cursor) {
                $replacements[] = new Text(substr($value, $cursor, $offset - $cursor));
            }

            $replacements[] = new Mention($matches[1][$index][0]);

            $cursor = $offset + strlen($whole);
        }

        if ($cursor < strlen($value)) {
            $replacements[] = new Text(substr($value, $cursor));
        }

        $anchor = $text;

        foreach ($replacements as $replacement) {
            $anchor->insertAfter($replacement);
            $anchor = $replacement;
        }

        $text->detach();
    }
}
