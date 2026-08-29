<?php

namespace justinholtweb\stopsign\models;

use DateTime;

/**
 * Who holds the lock on an element, if anybody, and what this viewer may do about it.
 */
class LockState
{
    public function __construct(
        public readonly bool $applicable,
        public readonly ?int $holderId = null,
        public readonly ?string $holderName = null,
        public readonly bool $isMine = false,
        public readonly ?DateTime $expiryDate = null,
        public readonly bool $canTakeOver = false,
        public readonly bool $enforced = false,
    ) {
    }

    /** Locking is switched off, or this element is not in scope for it. */
    public static function notApplicable(): self
    {
        return new self(applicable: false);
    }

    public function isHeldByAnotherUser(): bool
    {
        return $this->applicable && $this->holderId !== null && !$this->isMine;
    }

    public function toArray(): array
    {
        return [
            'applicable' => $this->applicable,
            'holderId' => $this->holderId,
            'holderName' => $this->holderName,
            'isMine' => $this->isMine,
            'heldByOther' => $this->isHeldByAnotherUser(),
            'canTakeOver' => $this->canTakeOver,
            'enforced' => $this->enforced,
            'expiresInSeconds' => $this->expiryDate
                ? max(0, $this->expiryDate->getTimestamp() - time())
                : null,
        ];
    }
}
