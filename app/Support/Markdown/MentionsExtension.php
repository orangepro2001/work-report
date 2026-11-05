<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Turns `@name` into a styled mention, inside the parsed document.
 *
 * It runs as a document event rather than a regex over the rendered HTML: at that
 * point the tree still knows what is prose and what is code, a link target or an
 * attribute value, so a mention is never injected where it would corrupt markup.
 */
final class MentionsExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, new MentionsProcessor, -50);
        $environment->addRenderer(Mention::class, new MentionRenderer, 10);
    }
}
