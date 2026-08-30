<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Implemented in roadmaps/05. Registered now so the scheduler is complete. */
class ReconcilePipelines extends Command
{
    protected $signature = 'pipemind:reconcile-pipelines';

    protected $description = 'Reconcile pipelines stuck in a running state';

    public function handle(): int
    {
        $this->comment('Not yet implemented — see roadmaps/05.');

        return self::SUCCESS;
    }
}
