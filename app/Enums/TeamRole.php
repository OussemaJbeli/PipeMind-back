<?php

declare(strict_types=1);

namespace App\Enums;

enum TeamRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
    case VIEWER = 'viewer';

    /**
     * The capability matrix. The API returns these as a flat array so the frontend
     * calls can('remediation.approve') and never reimplements role logic.
     * One source of truth — do not duplicate this in policies or in Vue.
     *
     * @return array<int,string>
     */
    public function permissions(): array
    {
        $viewer = ['projects.view', 'pipelines.view', 'failures.view', 'analyses.view'];
        $member = [...$viewer, 'analysis.trigger', 'pipelines.retry', 'failures.resolve',
            'remediation.request', 'anomalies.acknowledge', 'feedback.submit'];
        $admin = [...$member, 'projects.manage', 'integrations.manage', 'ai.manage',
            'team.manage', 'remediation.approve', 'knowledge.manage'];

        return match ($this) {
            self::VIEWER => $viewer,
            self::MEMBER => $member,
            self::ADMIN => $admin,
            self::OWNER => [...$admin, 'policies.edit', 'team.delete', 'billing.manage'],
        };
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function atLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public function weight(): int
    {
        return match ($this) {
            self::VIEWER => 1,
            self::MEMBER => 2,
            self::ADMIN => 3,
            self::OWNER => 4,
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
