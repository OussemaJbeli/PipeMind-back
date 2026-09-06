<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SystemStatusController extends Controller
{
    public function __invoke(AiGateway $ai): array
    {
        return ['data' => [
            'database' => rescue(fn () => DB::select('select 1') !== [], false, report: false),
            'redis' => rescue(fn () => Redis::ping() !== null, false, report: false),
            // A failed analysis is not a failed pipeline. The UI has to be able
            // to tell the user which one is actually broken.
            'ai_service' => rescue(
                fn () => ['reachable' => true, ...$ai->info()],
                ['reachable' => false],
                report: false,
            ),
            'queue' => rescue(fn () => [
                'pending' => (int) Redis::llen('queues:ingestion') + (int) Redis::llen('queues:logs'),
                'failed' => DB::table('failed_jobs')->count(),
            ], null, report: false),
        ]];
    }
}
