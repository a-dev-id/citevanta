<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone who found the free audits fully booked and asked to be emailed
 * when new slots open (see AuditWaitlistNotifier).
 *
 * @property int $id
 * @property string $email
 * @property string $locale
 * @property CarbonInterface|null $notified_at
 */
#[Table('audit_waitlist')]
#[Fillable(['email', 'locale', 'notified_at'])]
class AuditWaitlist extends Model
{
    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }
}
