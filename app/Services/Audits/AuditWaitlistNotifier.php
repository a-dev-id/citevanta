<?php

namespace App\Services\Audits;

use App\Mail\AuditSlotsOpen;
use App\Models\AuditWaitlist;
use Illuminate\Support\Facades\Mail;

/**
 * Each morning: email people on the audit waitlist that slots are open,
 * at most as many as the daily audit limit, oldest first, and only while
 * the audits budget has room.
 */
final class AuditWaitlistNotifier
{
    public function __construct(private readonly AuditService $audits) {}

    public function join(string $email, string $locale): AuditWaitlist
    {
        $entry = AuditWaitlist::query()->firstOrNew(['email' => mb_strtolower(trim($email))]);
        $entry->fill(['locale' => $locale, 'notified_at' => null])->save();

        return $entry;
    }

    public function notify(): int
    {
        if (! $this->audits->moneyLeft()) {
            return 0;
        }

        $entries = AuditWaitlist::query()->whereNull('notified_at')->orderBy('id')
            ->limit(max(0, (int) config('citevanta.audit.daily_budget', 5)))->get();

        foreach ($entries as $entry) {
            $entry->update(['notified_at' => now()]);
            Mail::to($entry->email)->queue(new AuditSlotsOpen($entry));
        }

        return $entries->count();
    }
}
