<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:billing')]
#[Description('Trial notices, renewal invoices, reminders, past due and expiry')]
class BillingCommand extends Command
{
    public function handle(SubscriptionLifecycle $lifecycle): int
    {
        foreach ($lifecycle->run() as $step => $count) {
            $this->line(str_pad($step, 18).$count);
        }

        return self::SUCCESS;
    }
}
