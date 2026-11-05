<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\CollabTokenController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DocumentsController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\MarkdownPreviewController;
use App\Http\Controllers\SsoAuthController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\ForceMountPath;
use App\Http\Middleware\RequireActor;
use Illuminate\Support\Facades\Route;

/*
 * Everything is mounted under the reverse proxy's path (/report). The prefix is
 * applied in bootstrap/app.php from config('workreport.base_path'), so route
 * names here stay prefix-free and url() adds it automatically.
 *
 * Signing in is the SSO application at 521985.xyz/sso (docs/auth-seam.md); the
 * three routes below the board are the whole public surface.
 */

Route::middleware(RequireActor::class)->group(function () {
    Route::get('/', [BoardController::class, 'index'])->name('board');

    // Board views: kanban (default), list, backlog — same data, three lenses.
    Route::get('/board', [BoardController::class, 'index'])->name('board.index');
    Route::get('/list', [BoardController::class, 'list'])->name('board.list');
    Route::get('/backlog', [BoardController::class, 'backlog'])->name('board.backlog');

    // Issue drawer: the panel markup, fetched into the right-hand pane.
    Route::get('/issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
    Route::post('/issues', [IssueController::class, 'store'])->name('issues.store');
    Route::patch('/issues/{issue}', [IssueController::class, 'update'])->name('issues.update');
    Route::delete('/issues/{issue}', [IssueController::class, 'destroy'])->name('issues.destroy');

    // Optimistic board moves: returns the recomputed column payload as JSON.
    Route::post('/issues/{issue}/move', [IssueController::class, 'move'])->name('issues.move');
    Route::post('/issues/{issue}/comments', [IssueController::class, 'comment'])->name('issues.comments');
    Route::delete('/comments/{comment}', [IssueController::class, 'destroyComment'])->name('comments.destroy');

    // 일보 — the workbook grid.
    Route::get('/daily', DailyReportController::class)->name('daily');

    // Excel export.
    Route::get('/export.xlsx', ExportController::class)->name('export');

    // Document library (docs/documents.md): an internal assets shelf, not a wiki engine.
    Route::get('/docs', [DocumentsController::class, 'index'])->name('docs.index');
    Route::post('/docs', [DocumentsController::class, 'store'])->name('docs.store');
    Route::get('/docs/{document:slug}', [DocumentsController::class, 'show'])->name('docs.show');
    Route::patch('/docs/{document:slug}', [DocumentsController::class, 'update'])->name('docs.update');
    Route::get('/docs/{document:slug}/state', [DocumentsController::class, 'state'])->name('docs.state');
    // The pass for the real-time room; authorisation happens here, once.
    Route::post('/docs/{document:slug}/collab', CollabTokenController::class)->name('docs.collab');
    Route::post('/docs/{document:slug}/restore/{revision}', [DocumentsController::class, 'restore'])->name('docs.restore');
    Route::post('/docs/{document:slug}/share', [DocumentsController::class, 'share'])->name('docs.share');
    Route::delete('/docs/{document:slug}/share/{member}', [DocumentsController::class, 'unshare'])->name('docs.unshare');

    // Images for Markdown: stored on this host, served through the app so a private
    // document's pictures stay private (docs/documents.md).
    Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');
    Route::get('/uploads/{attachment}', [UploadController::class, 'show'])->name('uploads.show');

    // The editor's preview pane, rendered by the same class that renders saved
    // comments (app/Support/Markdown.php).
    Route::post('/markdown', MarkdownPreviewController::class)->name('markdown.preview');
});

// The authorization-code flow. These must stay reachable without a session that
// carries claims, or a first-time visitor could never get one.
Route::get('/auth/sso/redirect', [SsoAuthController::class, 'redirect'])->name('sso.redirect');
Route::get('/auth/sso/callback', [SsoAuthController::class, 'callback'])->name('sso.callback');
Route::post('/auth/logout', [SsoAuthController::class, 'logout'])->name('logout');
Route::view('/auth/signed-out', 'auth.signed-out')->name('signed-out');

// Health: no session and no URL rewriting, always on the bare path. The container
// health check, tailscale and monitoring all expect a fixed location.
Route::get('/healthz', fn () => response('ok', 200)->header('Content-Type', 'text/plain'))
    ->withoutMiddleware(ForceMountPath::class)
    ->name('healthz');
