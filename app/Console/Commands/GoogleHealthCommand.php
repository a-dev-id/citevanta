<?php

namespace App\Console\Commands;

use App\Jobs\GoogleHealthJob;
use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-health')]
#[Description('Queue the weekly tracking health check for every linked hotel')]
class GoogleHealthCommand extends Command
{
    public function handle(GoogleAgent $agent): int
    {
        if ($agent->isBroken()) {
            $this->warn('Google is disconnected: reconnect in Admin > Integrations > Google.');

            return self::SUCCESS;
        }

        $links = HotelGoogleLink::query()->get();
        $links->each(fn (HotelGoogleLink $link) => GoogleHealthJob::dispatchAll($link));
        $this->line($links->count().' hotel(s) checked.');

        return self::SUCCESS;
    }
}
