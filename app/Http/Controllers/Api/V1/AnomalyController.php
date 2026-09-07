<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Project;
use Illuminate\Http\Request;

class AnomalyController extends Controller
{
    public function index(Request $request, Project $project): array
    {
        $anomalies = Anomaly::query()
            ->where('project_id', $project->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when(! $request->filled('status'), fn ($q) => $q->whereIn('status', ['open', 'acknowledged']))
            ->reorder('detected_at', 'desc')
            ->limit(100)
            ->get();

        return ['data' => $anomalies->map(fn (Anomaly $a) => $this->present($a))->all()];
    }

    public function acknowledge(Request $request, Anomaly $anomaly): array
    {
        $anomaly->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        return ['data' => $this->present($anomaly->fresh())];
    }

    public function resolve(Anomaly $anomaly): array
    {
        $anomaly->update(['status' => 'resolved']);

        return ['data' => $this->present($anomaly->fresh())];
    }

    /**
     * "Not an issue" is a first-class action, not a hidden dismiss.
     *
     * It records `false_positive`, which is what feeds threshold tuning — and a
     * detector nobody can push back on will be ignored within a week, taking the
     * genuine alerts with it.
     */
    public function falsePositive(Request $request, Anomaly $anomaly): array
    {
        $anomaly->update([
            'status' => 'false_positive',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        activity_log(
            $anomaly->project,
            'anomaly.false_positive',
            'info',
            $anomaly->project?->name ?? 'PipeMind',
            "Marked not an issue: {$anomaly->title}",
            $anomaly,
        );

        return ['data' => $this->present($anomaly->fresh())];
    }

    /** @return array<string,mixed> */
    private function present(Anomaly $anomaly): array
    {
        return [
            'uuid' => $anomaly->uuid,
            'type' => $anomaly->type,
            'severity' => $anomaly->severity->value,
            'status' => $anomaly->status,
            'metric_name' => $anomaly->metric_name,
            'observed_value' => (float) $anomaly->observed_value,
            'baseline_value' => (float) $anomaly->baseline_value,
            'deviation_ratio' => $anomaly->deviation_ratio !== null ? (float) $anomaly->deviation_ratio : null,
            'z_score' => $anomaly->z_score !== null ? (float) $anomaly->z_score : null,
            'detection_method' => $anomaly->detection_method,
            'title' => $anomaly->title,
            'description' => $anomaly->description,
            'possible_causes' => $anomaly->possible_causes ?? [],
            'detected_at' => $anomaly->detected_at?->toIso8601String(),
            'acknowledged_at' => $anomaly->acknowledged_at?->toIso8601String(),
        ];
    }
}
