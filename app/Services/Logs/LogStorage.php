<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\JobLog;
use App\Models\PipelineJob;
use Illuminate\Support\Facades\Storage;

/**
 * The object in MinIO/S3 is the immutable, UNREDACTED original — analysis has to
 * be reproducible. Postgres keeps the metadata and the extracted excerpt.
 */
class LogStorage
{
    public function store(PipelineJob $job, string $contents): JobLog
    {
        $project = $job->pipeline->project;
        $max = (int) config('pipemind.ingestion.max_log_bytes');

        $originalSize = strlen($contents);
        $truncated = false;

        if ($originalSize > $max) {
            // Keep the TAIL. The error is at the end of a CI log essentially always.
            $contents = sprintf("…[truncated: %d bytes, keeping the last %d]…\n", $originalSize, $max)
                .substr($contents, -$max);
            $truncated = true;
        }

        $path = sprintf(
            'logs/%s/%s/%d.log',
            $project->uuid,
            $job->pipeline->iid ?? $job->pipeline_id,
            $job->id,
        );

        Storage::disk('logs')->put($path, $contents, ['ContentType' => 'text/plain']);

        $log = JobLog::updateOrCreate(
            ['job_id' => $job->id],
            [
                'pipeline_id' => $job->pipeline_id,
                'project_id' => $project->id,
                'storage_disk' => 'logs',
                'storage_path' => $path,
                'size_bytes' => $originalSize,
                'line_count' => substr_count($contents, "\n") + 1,
                'checksum_sha256' => hash('sha256', $contents),
                'truncated' => $truncated,
                'fetched_at' => now(),
            ],
        );

        $job->forceFill(['log_fetched' => true])->save();

        return $log;
    }

    public function read(JobLog $log): string
    {
        return (string) Storage::disk($log->storage_disk)->get($log->storage_path);
    }

    /** Never stream 50 MB through the API. */
    public function temporaryUrl(JobLog $log, int $minutes = 15): string
    {
        return Storage::disk($log->storage_disk)
            ->temporaryUrl($log->storage_path, now()->addMinutes($minutes));
    }
}
