<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Implemented in roadmaps/19. Registered now so the scheduler is complete. */
class ComputeBaselines extends Command
{
    protected $signature = 'pipemind:compute-baselines';

    protected $description = 'Recompute per-job duration baselines';

    public function handle(): int
    {
        $this->comment('Not yet implemented — see roadmaps/19.');

        return self::SUCCESS;
    }
}
