<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Implemented in roadmaps/05. Registered now so the scheduler is complete. */
class Prune extends Command
{
    protected $signature = 'pipemind:prune';

    protected $description = 'Prune records past their retention window';

    public function handle(): int
    {
        $this->comment('Not yet implemented — see roadmaps/05.');

        return self::SUCCESS;
    }
}
