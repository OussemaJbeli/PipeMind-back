<?php

declare(strict_types=1);

namespace App\Services\Remediation;

/**
 * The outcome of the policy gate, and the reason for it.
 *
 * The reason is not decoration. A gate that says "not allowed" without saying
 * why teaches people to distrust it, and the reason is what the approval dialog
 * shows above the button — so it is written for a developer to read, naming the
 * limit that was hit and the value that hit it.
 */
final readonly class PolicyDecision
{
    public const AUTO_ALLOWED = 'auto_allowed';

    public const REQUIRES_APPROVAL = 'requires_approval';

    public const FORBIDDEN = 'forbidden';

    private function __construct(
        public string $decision,
        public string $reason,
    ) {}

    public static function autoAllowed(string $reason): self
    {
        return new self(self::AUTO_ALLOWED, $reason);
    }

    public static function requiresApproval(string $reason): self
    {
        return new self(self::REQUIRES_APPROVAL, $reason);
    }

    public static function forbidden(string $reason): self
    {
        return new self(self::FORBIDDEN, $reason);
    }

    public function isForbidden(): bool
    {
        return $this->decision === self::FORBIDDEN;
    }

    public function isAutomatic(): bool
    {
        return $this->decision === self::AUTO_ALLOWED;
    }

    public function needsApproval(): bool
    {
        return $this->decision === self::REQUIRES_APPROVAL;
    }

    /** @return array{decision:string,reason:string} */
    public function toArray(): array
    {
        return ['decision' => $this->decision, 'reason' => $this->reason];
    }
}
