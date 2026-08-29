<?php

namespace justinholtweb\stopsign\models;

use craft\elements\User;
use DateTime;

/**
 * One other party in the element, rendered down to what a warning needs to say.
 */
class Occupant
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly string $thumbHtml,
        public readonly string $intent,
        public readonly bool $dirty,
        public readonly DateTime $firstSeen,
        public readonly DateTime $lastSeen,
        public readonly ?int $draftId,
        public readonly bool $provisional,
        /** Whether this is the same account as the person being warned, in another tab. */
        public readonly bool $isSelf,
    ) {
    }

    public static function fromUser(User $user, array $row, bool $isSelf): self
    {
        return new self(
            userId: (int)$row['userId'],
            name: $user->getName(),
            thumbHtml: $user->getThumbHtml(30) ?? '',
            intent: (string)$row['intent'],
            dirty: (bool)$row['dirty'],
            firstSeen: new DateTime($row['firstSeen'], new \DateTimeZone('UTC')),
            lastSeen: new DateTime($row['lastSeen'], new \DateTimeZone('UTC')),
            draftId: $row['draftId'] !== null ? (int)$row['draftId'] : null,
            provisional: (bool)$row['provisional'],
            isSelf: $isSelf,
        );
    }

    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'name' => $this->name,
            'thumbHtml' => $this->thumbHtml,
            'intent' => $this->intent,
            'dirty' => $this->dirty,
            'isSelf' => $this->isSelf,
            'sinceSeconds' => max(0, time() - $this->firstSeen->getTimestamp()),
        ];
    }
}
