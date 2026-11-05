<?php

namespace App\Support;

use App\Support\Markdown\ImageGuardExtension;
use App\Support\Markdown\MentionsExtension;
use App\Support\Markdown\Tasks\TasksExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The one Markdown renderer of the application.
 *
 * `league/commonmark` is the same library Laravel ships, so the dialect is the
 * GitHub one people already type: tables, checklists, ~~strikethrough~~, bare
 * URLs. Two safety settings are deliberate, not defaults:
 *
 *   html_input=escape       raw HTML in a comment is shown as text, never rendered
 *   allow_unsafe_links=false  javascript:, data: and file: links are dropped
 *   images                     only this application's own uploads may render
 *
 * `escape` rather than `strip`: stripping deleted everything in the same line as
 * the tag (CommonMark treats a leading `<script>` as a block spanning the line),
 * so people lost text they could see they had typed. Escaped, it is visible and
 * inert.
 *
 * Comments store Markdown verbatim and are rendered on the way out, so improving
 * this class improves every comment ever written.
 */
final class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return (string) self::converter()->convert($markdown);
    }

    private static function converter(): MarkdownConverter
    {
        return self::$converter ??= new MarkdownConverter(self::environment());
    }

    private static function environment(): Environment
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            // Deep nesting is a paste accident, not a feature; the limit keeps a
            // pathological document from becoming a CPU event.
            'max_nesting_level' => 25,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new TaskListExtension);
        $environment->addExtension(new MentionsExtension);
        $environment->addExtension(new ImageGuardExtension);
        $environment->addExtension(new TasksExtension);

        return $environment;
    }
}
