<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Implemented in roadmaps/10. Registered now so the scheduler is complete. */
class RollupMetrics extends Command
{
    protected $signature = 'pipemind:rollup-metrics';

    protected $description = 'Roll up daily project metrics';

    public function handle(): int
    {
        $this->comment('Not yet implemented — see roadmaps/10.');

        return self::SUCCESS;
    }
}
