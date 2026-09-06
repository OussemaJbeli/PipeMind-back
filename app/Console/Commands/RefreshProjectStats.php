<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class RefreshProjectStats extends Command
{
    protected $signature = 'pipemind:refresh-project-stats {--project= : Limit to one project id}';

    protected $description = 'Recompute denormalised project counters';

    public function handle(): int
    {
        $query = Project::withoutGlobalScopes()->where('is_active', true);

        if ($id = $this->option('project')) {
            $query->where('id', $id);
        }

        $count = 0;

        $query->pluck('id')->each(function (int $id) use (&$count): void {
            \App\Jobs\RefreshProjectStats::dispatchSync($id);
            $count++;
        });

        $this->info("Refreshed {$count} project(s).");

        return self::SUCCESS;
    }
}
