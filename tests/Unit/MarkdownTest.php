<?php

namespace Tests\Unit;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * The renderer every stored comment goes through (app/Support/Markdown.php).
 *
 * These cases exist because the previous implementation was a stack of regexes:
 * it could not do tables, it mangled nested emphasis, and it turned any `@word`
 * into a mention — including inside code. The library handles the dialect; the
 * tests pin the two things that are our choice, not its defaults.
 */
class MarkdownTest extends TestCase
{
    public function test_github_dialect_is_available(): void
    {
        $html = Markdown::toHtml("| a | b |\n| - | - |\n| 1 | 2 |");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>a</th>', $html);

        $list = Markdown::toHtml("- [x] 끝난 일\n- [ ] 남은 일");
        $this->assertStringContainsString('type="checkbox"', $list);
        $this->assertStringContainsString('checked', $list);

        $this->assertStringContainsString('<del>지움</del>', Markdown::toHtml('~~지움~~'));
        $this->assertStringContainsString('<a href="https://example.com"', Markdown::toHtml('https://example.com'));
    }

    public function test_emphasis_and_code_keep_their_meaning(): void
    {
        $html = Markdown::toHtml('**굵게** *기울임* `코드` 그리고 `````fence`````');

        $this->assertStringContainsString('<strong>굵게</strong>', $html);
        $this->assertStringContainsString('<em>기울임</em>', $html);
        $this->assertStringContainsString('<code>코드</code>', $html);
        $this->assertStringContainsString('<pre><code', Markdown::toHtml("```php\n\necho 1;\n\n```"));
    }

    public function test_raw_html_is_shown_as_text_and_unsafe_links_are_dropped(): void
    {
        $html = Markdown::toHtml("<script>alert(1)</script>\n\n[클릭](javascript:alert(1)) [좋은 링크](https://ok.test)");

        // Escaped, not stripped: nothing executes, and nothing typed disappears.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // The unsafe link is dropped, so no href can carry the scheme.
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('href="https://ok.test"', $html);
    }

    public function test_mentions_are_marked_up_in_prose_only(): void
    {
        $html = Markdown::toHtml('@이성민 확인 부탁드립니다 `@코드안` @yzy');

        $this->assertStringContainsString('<span class="mention">@이성민</span>', $html);
        $this->assertStringContainsString('<span class="mention">@yzy</span>', $html);
        // Inside code the @ is literal text, not an invitation.
        $this->assertStringNotContainsString('<span class="mention">@코드안', $html);
        $this->assertStringContainsString('<code>@코드안</code>', $html);
    }

    public function test_mentions_in_link_labels_are_left_alone(): void
    {
        $html = Markdown::toHtml('[@이성민](https://ok.test)');

        $this->assertStringContainsString('<a href="https://ok.test">', $html);
        $this->assertStringNotContainsString('<span class="mention">', $html);
    }

    public function test_blank_input_renders_nothing(): void
    {
        $this->assertSame('', Markdown::toHtml(null));
        $this->assertSame('', Markdown::toHtml("   \n "));
    }
}
