<?php

namespace App\Console\Commands;

use App\Services\Audits\AuditWaitlistNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:audit-waitlist')]
#[Description('Email people on the free audit waitlist that new slots are open')]
class AuditWaitlistCommand extends Command
{
    public function handle(AuditWaitlistNotifier $notifier): int
    {
        $this->line($notifier->notify().' waitlist email(s) queued.');

        return self::SUCCESS;
    }
}
