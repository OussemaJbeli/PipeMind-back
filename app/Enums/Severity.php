<?php

declare(strict_types=1);

namespace App\Enums;

enum Severity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    public function exceeds(self $other): bool
    {
        return $this->weight() > $other->weight();
    }

    /** Used by the policy engine: the default branch escalates risk one level. */
    public function escalate(): self
    {
        return match ($this) {
            self::LOW => self::MEDIUM,
            self::MEDIUM => self::HIGH,
            self::HIGH, self::CRITICAL => self::CRITICAL,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => '#8B93A1',
            self::MEDIUM => '#F5A524',
            self::HIGH => '#F04438',
            self::CRITICAL => '#FF4D4D',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
