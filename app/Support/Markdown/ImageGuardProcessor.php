<?php

namespace App\Support\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Images may only point at this application's own upload route.
 *
 * The requirement is that documentation images live on this host, not on someone
 * else's image host — so an external URL is not just inadvisable, it is refused.
 * That is also the privacy guard: a pasted `https://tracker.example/pixel.gif`
 * would otherwise fire for every reader of the document.
 *
 * A refused image becomes its own alt text in brackets, so the author sees what
 * happened instead of a silently missing picture.
 */
final class ImageGuardProcessor
{
    /**
     * `uploads/12`, `/report/uploads/12` or `/report/uploads/12/shot.png` — a path
     * this application serves. The trailing filename is decoration; the id is what
     * the controller resolves.
     *
     * Absolute URLs are refused even when they point back here: the uploader always
     * writes a relative path, and a rule that has to recognise "our own host" inside
     * rendered markdown would be one proxy header away from being wrong.
     */
    private const LOCAL = '#^(?:[^/]+/)?uploads/\d+(?:/[^/]+)?$#';

    public function __invoke(DocumentParsedEvent $event): void
    {
        // Collect first, mutate afterwards: replacing a node while the walker is
        // standing on it makes the walker skip the *next* sibling, which is exactly
        // how the second image of a paragraph used to survive the guard.
        $images = [];
        $walker = $event->getDocument()->walker();

        while ($node = $walker->next()) {
            if ($node->isEntering() && $node->getNode() instanceof Image) {
                $images[] = $node->getNode();
            }
        }

        foreach ($images as $image) {
            $url = (string) $image->getUrl();
            $parsed = parse_url($url);
            $path = ltrim((string) ($parsed['path'] ?? $url), '/');

            if (! isset($parsed['host']) && preg_match(self::LOCAL, $path) === 1) {
                // Only the scheme and host are ours; drop any title/alt trickery.
                $image->setTitle(null);

                continue;
            }

            $alt = $image->firstChild() instanceof Text ? $image->firstChild()->getLiteral() : '';

            $image->replaceWith(new Text($alt !== '' ? "[{$alt}: 외부 이미지 차단]" : '[외부 이미지 차단]'));
        }
    }
}
