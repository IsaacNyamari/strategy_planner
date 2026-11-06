<?php

namespace App\Enums;

/**
 * Each service has two sessions, each with its own lead minister:
 * the praise session and the worship session. Everyone else is a backup.
 */
enum MinistryRole: string
{
    case PRAISE_LEAD = 'praise_lead';
    case WORSHIP_LEAD = 'worship_lead';
    case BACKUP = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::PRAISE_LEAD => 'Praise leader',
            self::WORSHIP_LEAD => 'Worship leader',
            self::BACKUP => 'Backup',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PRAISE_LEAD => 'Praise',
            self::WORSHIP_LEAD => 'Worship',
            self::BACKUP => 'Backup',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PRAISE_LEAD => 'amber',
            self::WORSHIP_LEAD => 'violet',
            self::BACKUP => 'zinc',
        };
    }

    public function isLead(): bool
    {
        return $this !== self::BACKUP;
    }

    /** Member column that marks someone as eligible for this lead role. */
    public function eligibilityColumn(): ?string
    {
        return match ($this) {
            self::PRAISE_LEAD => 'can_lead_praise',
            self::WORSHIP_LEAD => 'can_lead_worship',
            self::BACKUP => null,
        };
    }

    /** @return list<self> */
    public static function leads(): array
    {
        return [self::PRAISE_LEAD, self::WORSHIP_LEAD];
    }
}
