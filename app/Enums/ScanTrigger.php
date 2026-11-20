<?php

namespace App\Enums;

/**
 * Why a scan run was started.
 */
enum ScanTrigger: string
{
    /** Queued by the daily scheduler (citevanta:dispatch-due-scans). */
    case Scheduled = 'scheduled';

    /** "Scan now" by a team member; counts toward the team's manual allowance. */
    case Manual = 'manual';

    /** "Scan now" by an internal admin; never counts toward the allowance. */
    case Admin = 'admin';

    /** The first scan queued when the setup wizard is finished. */
    case Setup = 'setup';

    /** The scan:test console command. */
    case Cli = 'cli';

    /** A free AI visibility audit from the public website (no team). */
    case Audit = 'audit';

    /** The free audit's answers, imported as a trial hotel's first scan (no AI calls). */
    case AuditImport = 'audit_import';

    public function label(): string
    {
        return match ($this) {
            self::Cli => 'CLI',
            self::Audit => 'Free audit',
            self::AuditImport => 'From your free audit',
            default => ucfirst($this->value),
        };
    }
}
