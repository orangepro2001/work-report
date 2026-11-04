<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\MemberSwitchController;
use App\Http\Middleware\ForceMountPath;
use Illuminate\Support\Facades\Route;

/*
 * Everything is mounted under the reverse proxy's path (/report). The prefix is
 * applied in bootstrap/app.php from config('workreport.base_path'), so route
 * names here stay prefix-free and url() adds it automatically.
 */

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

// Until SSO lands, the acting member is a session value (docs/auth-seam.md).
Route::post('/actor', MemberSwitchController::class)->name('actor.switch');

// Health: no session and no URL rewriting, always on the bare path. The container
// health check, tailscale and monitoring all expect a fixed location.
Route::get('/healthz', fn () => response('ok', 200)->header('Content-Type', 'text/plain'))
    ->withoutMiddleware(ForceMountPath::class)
    ->name('healthz');
