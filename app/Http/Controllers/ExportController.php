<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Project;
use App\Services\Excel\ReportExporter;
use App\Services\Excel\XlsxPackager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly ReportExporter $exporter,
        private readonly XlsxPackager $packager,
    ) {}

    /**
     * GET /export.xlsx?date=YYYY-MM-DD[&project=..&member=..&status=..]
     *
     * Streams the workbook so nothing is written to the read-only container rootfs:
     * PhpSpreadsheet needs a real file handle, so a php://temp stream is used and
     * the bytes are copied out in one pass.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'project' => ['nullable', 'integer', 'exists:projects,id'],
            'member' => ['nullable', 'integer', 'exists:members,id'],
            'status' => ['nullable', 'in:planned,in_progress,done,on_hold'],
        ]);

        $date = CarbonImmutable::parse($validated['date'] ?? 'now', config('workreport.timezone'));

        [$book, $filename, $ascii] = $this->exporter->build($validated + ['date' => $date->toDateString()]);

        $tmp = tempnam(sys_get_temp_dir(), 'wr_export_');
        $this->packager->save($book, $tmp);
        $book->disconnectWorksheets();

        return response()->streamDownload(
            function () use ($tmp) {
                $handle = fopen($tmp, 'rb');
                fpassthru($handle);
                fclose($handle);
                @unlink($tmp);
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                // RFC 5987 so Korean filenames survive every browser/proxy.
                'Content-Disposition' => sprintf(
                    "attachment; filename=\"%s\"; filename*=UTF-8''%s",
                    $ascii,
                    rawurlencode($filename),
                ),
                'Cache-Control' => 'private, max-age=0, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
