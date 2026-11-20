<?php

namespace App\Services\Budget;

use App\Models\User;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Emails every internal admin (users.is_admin).
 */
final class AdminMailer
{
    /**
     * @param  Closure(): Mailable  $mail
     */
    public function send(Closure $mail): void
    {
        User::query()->where('is_admin', true)->get()->each(fn (User $admin) => Mail::to($admin)->queue($mail()));
    }
}
