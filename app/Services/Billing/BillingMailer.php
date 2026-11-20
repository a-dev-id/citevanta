<?php

namespace App\Services\Billing;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Billing emails go to the team's owners (queued and throttled, see ThrottledMail).
 */
final class BillingMailer
{
    /**
     * @param  Closure(User): Mailable  $mail
     */
    public function toOwners(Team $team, Closure $mail): void
    {
        $team->owners()->get()->each(fn (User $owner) => Mail::to($owner)->queue($mail($owner)));
    }
}
