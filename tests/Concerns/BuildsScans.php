<?php

namespace Tests\Concerns;

use App\Enums\Persona;
use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Services\Scanning\ScanService;
use Illuminate\Support\Carbon;

/**
 * A set-up hotel with prompts and competitors, ready to scan with the FakeEngine.
 */
trait BuildsScans
{
    protected function scannableHotel(int $prompts = 3, Plan $plan = Plan::Starter, array $attributes = []): Hotel
    {
        $team = Team::factory()->plan($plan)->create();
        $hotel = Hotel::factory()->for($team)->create($attributes + [
            'name' => 'Alaya Resort Ubud',
            'city' => 'Ubud',
            'country' => 'Indonesia',
            'website' => 'https://alayahotels.com',
            'hotel_type' => 'resort',
            'setup_step' => 4,
            'setup_completed_at' => now(),
        ]);

        $personas = Persona::cases();
        foreach ($prompts > 0 ? range(1, $prompts) : [] as $i) {
            $hotel->prompts()->create([
                'text' => "Which resort in Ubud is best for traveller type number {$i}?",
                'persona' => $personas[($i - 1) % count($personas)],
                'language' => 'en',
                'is_active' => true,
            ]);
        }

        $hotel->competitors()->create(['name' => 'Kayon Jungle Resort', 'website' => 'https://kayonresort.com']);
        $hotel->competitors()->create(['name' => 'Maya Ubud Resort']);

        return $hotel;
    }

    /**
     * Run completed FakeEngine scans of a hotel, oldest first, one per given mention rate.
     *
     * @param  list<float>  $mentionRates
     * @return list<ScanRun>
     */
    protected function completedScans(Hotel $hotel, array $mentionRates): array
    {
        $runs = [];

        foreach (array_values($mentionRates) as $i => $rate) {
            $this->travelTo(Carbon::now()->subDays((count($mentionRates) - $i) * 7));
            config(['citevanta.fake.mention_rate' => $rate, 'citevanta.fake.salt' => "scan-{$i}"]);
            $run = $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'pending', 'trigger' => ScanTrigger::Scheduled, 'status' => ScanStatus::Queued]);
            app(ScanService::class)->runSync($run);
            $runs[] = $run->fresh();
            $this->travelBack();
        }

        return $runs;
    }
}
