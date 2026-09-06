<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PipelineJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JobLogController extends Controller
{
    /**
     * `?mode=excerpt` (default) returns the stored redacted excerpt.
     * `?mode=full` streams the object from storage.
     *
     * The stored object is the raw, unredacted log — it is the evidence, and
     * redacting it at rest would destroy the only copy. Redaction happens on the
     * way out instead, so the excerpt shown in the UI and sent to any model is
     * clean while the original stays intact.
     */
    public function show(Request $request, PipelineJob $job): array
    {
        $log = $job->log;

        if (! $log) {
            return ['data' => [
                'available' => false,
                'reason' => $job->log_fetched
                    ? 'The provider no longer has this log.'
                    : 'The log has not been fetched yet.',
            ]];
        }

        $full = $request->string('mode')->toString() === 'full';

        return ['data' => [
            'available' => true,
            'mode' => $full ? 'full' : 'excerpt',
            'excerpt' => $log->excerpt,
            'excerpt_start_line' => $log->excerpt_start_line,
            'excerpt_end_line' => $log->excerpt_end_line,
            'error_block' => $log->error_block,
            'stack_trace' => $log->stack_trace,
            'line_count' => $log->line_count,
            'size_bytes' => $log->size_bytes,
            'truncated' => (bool) $log->truncated,
            'is_redacted' => (bool) $log->is_redacted,
            'redaction_count' => (int) $log->redaction_count,
            'redaction_types' => $log->redaction_types ?? [],
            'content' => $full ? $this->content($log) : null,
        ]];
    }

    private function content(object $log): ?string
    {
        if (! $log->storage_path) {
            return null;
        }

        return rescue(
            fn () => Storage::disk($log->storage_disk)->get($log->storage_path),
            null,
            report: false,
        );
    }
}
