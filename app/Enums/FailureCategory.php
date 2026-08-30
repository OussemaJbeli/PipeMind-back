<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The 13 failure categories. Contract shared with:
 *   - PipeMind-data/taxonomy/failure-categories.md
 *   - PipeMind-ai  app/services/classifier/rules.py
 *   - PipeMind-front  src/composables/useCategoryMeta.ts
 *
 * Colours MUST match the frontend exactly, or the donut and the bar list disagree.
 */
enum FailureCategory: string
{
    case BUILD = 'BUILD';
    case TEST = 'TEST';
    case DEPENDENCY = 'DEPENDENCY';
    case DATABASE = 'DATABASE';
    case NETWORK = 'NETWORK';
    case DOCKER = 'DOCKER';
    case DEPLOYMENT = 'DEPLOYMENT';
    case CONFIGURATION = 'CONFIGURATION';
    case AUTHENTICATION = 'AUTHENTICATION';
    case PERMISSION = 'PERMISSION';
    case INFRASTRUCTURE = 'INFRASTRUCTURE';
    case RESOURCE = 'RESOURCE';
    case UNKNOWN = 'UNKNOWN';

    public function color(): string
    {
        return match ($this) {
            self::DATABASE => '#A9E831',
            self::TEST => '#F04438',
            self::DEPENDENCY => '#F5A524',
            self::DOCKER => '#38BDF8',
            self::NETWORK => '#6366F1',
            self::BUILD => '#EC4899',
            self::DEPLOYMENT => '#14B8A6',
            self::CONFIGURATION => '#A855F7',
            self::AUTHENTICATION => '#F97316',
            self::PERMISSION => '#8B5CF6',
            self::INFRASTRUCTURE => '#0EA5E9',
            self::RESOURCE => '#EAB308',
            self::UNKNOWN => '#5C6472',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TEST => 'Tests',
            self::DEPENDENCY => 'Dependencies',
            self::AUTHENTICATION => 'Authentication',
            self::INFRASTRUCTURE => 'Infrastructure',
            self::CONFIGURATION => 'Configuration',
            default => ucfirst(strtolower($this->value)),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DATABASE => 'database',
            self::TEST => 'flask-conical',
            self::DEPENDENCY => 'package',
            self::DOCKER => 'container',
            self::NETWORK => 'wifi-off',
            self::BUILD => 'hammer',
            self::DEPLOYMENT => 'rocket',
            self::CONFIGURATION => 'settings-2',
            self::AUTHENTICATION => 'key-round',
            self::PERMISSION => 'lock',
            self::INFRASTRUCTURE => 'server',
            self::RESOURCE => 'cpu',
            self::UNKNOWN => 'help-circle',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
