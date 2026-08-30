<?php

declare(strict_types=1);

namespace App\Enums;

enum ActionType: string
{
    case INVESTIGATE = 'investigate';
    case RETRY_JOB = 'retry_job';
    case RETRY_PIPELINE = 'retry_pipeline';
    case CREATE_ISSUE = 'create_issue';
    case EDIT_FILE = 'edit_file';
    case UPDATE_CONFIG = 'update_config';
    case UPDATE_DEPENDENCY = 'update_dependency';
    case CREATE_MERGE_REQUEST = 'create_merge_request';
    case ROLLBACK_DEPLOYMENT = 'rollback_deployment';
    case MANUAL = 'manual';

    /**
     * Risk is a property of the action, assigned here — NEVER taken from the model.
     * A model that could label a production rollback "low risk" would be able to
     * talk its way past the policy engine.
     */
    public function risk(): Severity
    {
        return match ($this) {
            self::INVESTIGATE, self::RETRY_JOB,
            self::RETRY_PIPELINE, self::CREATE_ISSUE => Severity::LOW,

            self::EDIT_FILE, self::UPDATE_DEPENDENCY,
            self::CREATE_MERGE_REQUEST, self::MANUAL => Severity::MEDIUM,

            self::UPDATE_CONFIG => Severity::HIGH,
            self::ROLLBACK_DEPLOYMENT => Severity::CRITICAL,
        };
    }

    /** Does this action change anything outside PipeMind? */
    public function isMutating(): bool
    {
        return $this !== self::INVESTIGATE;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
