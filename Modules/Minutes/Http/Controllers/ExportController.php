<?php

namespace Modules\Minutes\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\MinutesExporter;

class ExportController extends Controller
{
    public function download(Meeting $meeting, string $format, MinutesExporter $exporter)
    {
        abort_unless($meeting->isReady(), 422, 'Minutes are not ready to export.');

        return match ($format) {
            'md' => response($exporter->markdown($meeting))
                ->header('Content-Type', 'text/markdown; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $exporter->filename($meeting, 'md') . '"'),
            'pdf' => response($exporter->pdf($meeting))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $exporter->filename($meeting, 'pdf') . '"'),
            'docx' => response($exporter->docx($meeting))
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                ->header('Content-Disposition', 'attachment; filename="' . $exporter->filename($meeting, 'docx') . '"'),
            default => abort(404, 'Unknown export format.'),
        };
    }
}
