<?php

namespace Database\Seeders;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\TeamRole;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Hotels\PromptService;
use App\Services\Scanning\ScanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Demo data: php artisan db:seed --class=DemoSeeder
 *
 * Safe to run again: it removes and recreates only its own users and team.
 * Scans run through the real jobs and finalizer with the FakeEngine (forced
 * here, so seeding never calls a paid API), its mention rate rising per scan
 * so the dashboard shows improvement.
 */
class DemoSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@citevanta.test';

    public const DEMO_EMAIL = 'demo@citevanta.test';

    public const TEAM_NAME = 'Demo Hotels';

    public const HOTEL_NAME = 'Kirana Hills Resort Ubud';

    public const DRAFT_HOTEL_NAME = 'Kirana Beach Villas Canggu';

    /**
     * Days ago, FakeEngine mention rate, trigger.
     *
     * @var list<array{0: int, 1: float, 2: ScanTrigger}>
     */
    private const SCANS = [
        [42, 0.25, ScanTrigger::Setup],
        [21, 0.45, ScanTrigger::Scheduled],
        [2, 0.65, ScanTrigger::Scheduled],
    ];

    public function run(): void
    {
        // Never create demo accounts with known passwords in production by accident.
        if (app()->isProduction() && ! $this->command?->option('force')) {
            $this->command?->error('DemoSeeder is blocked in production. Use php artisan citevanta:make-admin instead, or add --force if you really want demo data.');

            return;
        }

        $this->removeExisting();

        $team = Team::create([
            'name' => self::TEAM_NAME,
            'plan' => Plan::Pro,
            'trial_ends_at' => null,
            'timezone' => 'Asia/Makassar',
        ]);

        $demo = $this->user('Demo User', self::DEMO_EMAIL);
        $team->addMember($demo, TeamRole::Owner);
        $demo->switchTeam($team);

        $admin = $this->user('CiteVanta Admin', self::ADMIN_EMAIL);
        $admin->forceFill(['is_admin' => true])->save();
        $team->addMember($admin, TeamRole::Member);
        $admin->switchTeam($team);

        $hotel = $this->resort($team);
        $this->runScans($hotel);
        $this->draftHotel($team);

        $this->command?->info('Demo ready: '.self::DEMO_EMAIL.' / password and '.self::ADMIN_EMAIL.' / password');
    }

    private function removeExisting(): void
    {
        $users = User::whereIn('email', [self::ADMIN_EMAIL, self::DEMO_EMAIL])->get();

        // Deleting the team cascades to its hotels, prompts, competitors, scans and results.
        Team::where('name', self::TEAM_NAME)
            ->whereHas('users', fn ($query) => $query->whereIn('users.id', $users->pluck('id')))
            ->get()
            ->each->delete();

        $users->each->delete();
    }

    private function user(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function resort(Team $team): Hotel
    {
        $hotel = $team->hotels()->create([
            'name' => self::HOTEL_NAME,
            'city' => 'Ubud',
            'country' => 'Indonesia',
            'website' => 'https://kiranahills.example',
            'hotel_type' => 'resort',
            'star_rating' => 5,
            'guest_markets' => ['Australia', 'Germany'],
            'setup_step' => 4,
            'setup_completed_at' => now()->subDays(43),
        ]);

        $hotel->facts()->createMany(array_map(
            fn (array $fact, int $position) => ['key' => $fact[0], 'value' => $fact[1], 'position' => $position],
            [
                ['Price range per night', 'USD 220 - 650'],
                ['Number of rooms', '48 suites and 12 pool villas'],
                ['Swimming pool', 'Two infinity pools facing the Ayung valley'],
                ['Kids club', 'Yes, daily 9:00-17:00 for ages 4-12'],
                ['Spa', 'Full-service spa with Balinese treatments'],
                ['Restaurant', 'Two restaurants and a pool bar'],
                ['Distance to airport', '75 minutes by car to Ngurah Rai (DPS)'],
                ['Check-in time', '14:00'],
                ['Check-out time', '12:00'],
                ['Pet policy', 'No pets'],
                ['Free Wi-Fi', 'Yes, throughout the resort'],
                ['Parking', 'Free on-site parking'],
            ],
            array_keys(array_fill(0, 12, null)),
        ));

        foreach ([
            ['Bamboo Valley Retreat', 'https://bamboovalley.example'],
            ['Lotus Terrace Ubud', null],
            ['Sayan Ridge Villas', 'https://sayanridge.example'],
        ] as [$name, $website]) {
            $hotel->competitors()->create(['name' => $name, 'website' => $website]);
        }

        // 10 persona prompts + 1 per guest market, all active (Pro allows 50).
        app(PromptService::class)->generate($hotel);

        return $hotel;
    }

    private function runScans(Hotel $hotel): void
    {
        $original = config('citevanta');
        config(['citevanta.engine' => 'fake']);
        $realNow = Carbon::now();

        try {
            foreach (self::SCANS as $i => [$daysAgo, $mentionRate, $trigger]) {
                Date::setTestNow($realNow->copy()->subDays($daysAgo)->setTime(1, 5));
                config(['citevanta.fake.mention_rate' => $mentionRate, 'citevanta.fake.salt' => 'demo-scan-'.($i + 1)]);

                $scanRun = $hotel->scanRuns()->create([
                    'engine' => 'openai',
                    'model' => 'pending',
                    'trigger' => $trigger,
                    'status' => ScanStatus::Queued,
                    'cost_usd' => 0,
                ]);

                app(ScanService::class)->runSync($scanRun);
                $this->stampFinishedLater($scanRun);
            }
        } finally {
            Date::setTestNow();
            config(['citevanta' => $original]);
        }
    }

    /**
     * Fake answers arrive instantly; make each demo scan look like it took a few minutes.
     */
    private function stampFinishedLater(ScanRun $scanRun): void
    {
        $scanRun->refresh();
        $scanRun->forceFill(['finished_at' => $scanRun->started_at->addMinutes(7)])->save();
    }

    /**
     * A second hotel left at step 2 of the wizard, to show "Finish setup".
     * (The Pro plan includes one hotel; the seeder adds it anyway for the demo.)
     */
    private function draftHotel(Team $team): void
    {
        $team->hotels()->create([
            'name' => self::DRAFT_HOTEL_NAME,
            'city' => 'Canggu',
            'country' => 'Indonesia',
            'hotel_type' => 'villa',
            'guest_markets' => ['Australia'],
            'setup_step' => 2,
        ]);
    }
}
