<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Implemented in roadmaps/05. Registered now so the scheduler is complete. */
class RefreshProjectStats extends Command
{
    protected $signature = 'pipemind:refresh-project-stats';

    protected $description = 'Recompute denormalised project counters';

    public function handle(): int
    {
        $this->comment('Not yet implemented — see roadmaps/05.');

        return self::SUCCESS;
    }
}
