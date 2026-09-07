<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\FailureCategory;
use App\Http\Controllers\Controller;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * One query across everything the command palette can reach.
 *
 * Deliberately a single endpoint rather than four: the palette fires on every
 * keystroke, and four parallel requests per character is how a search box
 * becomes a load generator.
 */
class SearchController extends Controller
{
    private const PER_GROUP = 5;

    public function __invoke(Request $request): array
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2) {
            // One character matches almost everything, at full table-scan cost,
            // and is never what the user meant.
            return ['data' => ['query' => $term, 'groups' => []]];
        }

        $groups = array_values(array_filter([
            $this->projects($term),
            $this->pipelines($term),
            $this->failures($term),
            $this->signatures($term),
        ], fn (?array $group) => $group !== null));

        return ['data' => ['query' => $term, 'groups' => $groups]];
    }

    private function projects(string $term): ?array
    {
        $projects = Project::query()
            ->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('slug', 'ilike', "%{$term}%"))
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get(['uuid', 'name', 'slug', 'color', 'health_status']);

        return $projects->isEmpty() ? null : [
            'type' => 'project',
            'label' => 'Projects',
            'items' => $projects->map(fn (Project $project) => [
                'id' => $project->uuid,
                'title' => $project->name,
                'subtitle' => $project->health_status,
                'color' => $project->color,
                'route' => ['name' => 'project.overview', 'params' => ['slug' => $project->slug]],
            ])->all(),
        ];
    }

    /**
     * Pipelines by iid.
     *
     * "#821" is how people refer to a run, so a leading # is stripped and a
     * numeric term is matched exactly rather than as a substring — "82" should
     * not return #820, #821 and #8214 ranked by accident.
     */
    private function pipelines(string $term): ?array
    {
        $iid = ltrim($term, '#');

        if (! ctype_digit($iid)) {
            return null;
        }

        $pipelines = Pipeline::query()
            ->whereHas('project')
            ->where('iid', (int) $iid)
            ->with('project:id,slug,name')
            ->reorder('id', 'desc')
            ->limit(self::PER_GROUP)
            ->get();

        return $pipelines->isEmpty() ? null : [
            'type' => 'pipeline',
            'label' => 'Pipelines',
            'items' => $pipelines->map(fn (Pipeline $pipeline) => [
                'id' => $pipeline->uuid,
                'title' => "#{$pipeline->iid} · {$pipeline->project->name}",
                'subtitle' => trim($pipeline->status->value.' · '.$pipeline->ref),
                'route' => [
                    'name' => 'project.pipeline',
                    'params' => ['slug' => $pipeline->project->slug, 'iid' => $pipeline->iid],
                ],
            ])->all(),
        ];
    }

    private function failures(string $term): ?array
    {
        $category = FailureCategory::tryFrom(mb_strtoupper($term));

        $failures = Failure::query()
            ->where(fn ($q) => $q
                ->where('error_message', 'ilike', "%{$term}%")
                ->orWhere('subcategory', 'ilike', "%{$term}%")
                ->when($category, fn ($inner) => $inner->orWhere('category', $category->value)))
            ->with('project:id,slug,name')
            ->reorder('failed_at', 'desc')
            ->limit(self::PER_GROUP)
            ->get();

        return $failures->isEmpty() ? null : [
            'type' => 'failure',
            'label' => 'Failures',
            'items' => $failures->map(fn (Failure $failure) => [
                'id' => $failure->uuid,
                'title' => $failure->error_message ?? $failure->category->value,
                'subtitle' => trim($failure->category->value.' · '.$failure->project->name),
                'color' => $failure->category->color(),
                'route' => [
                    'name' => 'project.failure',
                    'params' => ['slug' => $failure->project->slug, 'uuid' => $failure->uuid],
                ],
            ])->all(),
        ];
    }

    private function signatures(string $term): ?array
    {
        $signatures = FailureSignature::query()
            ->where('is_known', true)
            ->where(fn ($q) => $q
                ->where('sample_error', 'ilike', "%{$term}%")
                ->orWhere('known_resolution', 'ilike', "%{$term}%"))
            ->reorder('occurrence_count', 'desc')
            ->limit(self::PER_GROUP)
            ->get();

        return $signatures->isEmpty() ? null : [
            'type' => 'signature',
            'label' => 'Known fixes',
            'items' => $signatures->map(fn (FailureSignature $signature) => [
                'id' => $signature->uuid,
                'title' => $signature->known_resolution ?? $signature->sample_error,
                'subtitle' => "seen {$signature->occurrence_count}× · {$signature->category->value}",
                // No route: signatures have no page of their own yet, and a link
                // to nowhere is worse than a result that only informs.
                'route' => null,
            ])->all(),
        ];
    }
}
