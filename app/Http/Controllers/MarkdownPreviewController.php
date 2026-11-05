<?php

namespace App\Http\Controllers;

use App\Support\Markdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Renders Markdown for the editor's preview pane.
 *
 * The editor could preview locally, but then the preview would be whatever the
 * browser's Markdown library does with the text — unsanitised, and subtly
 * different from what the person will actually see. Rendering it here means the
 * preview is the saved output, produced by the same class that renders saved
 * comments (app/Support/Markdown.php).
 */
final class MarkdownPreviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:20000'],
        ]);

        return response()->json(['html' => Markdown::toHtml($data['text'] ?? '')]);
    }
}
