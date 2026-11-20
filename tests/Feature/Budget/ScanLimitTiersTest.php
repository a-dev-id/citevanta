<?php

namespace Tests\Feature\Budget;

use App\Enums\Plan;
use App\Enums\ScanTrigger;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Analysis\AnswerExtractor;
use App\Services\Budget\AiCallContext;
use App\Services\Engines\OpenAiLimits;
use App\Services\Scanning\ScanCallRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Contracts\ClientContract;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;
use Throwable;

/**
 * Scan answer limits per tier: free audits and trials keep the cheapest
 * settings; paid and Private plan scans get more searches and output.
 */
class ScanLimitTiersTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    public function test_free_and_paid_tiers_have_their_own_limits_from_env(): void
    {
        $this->assertSame([2, 1200], [OpenAiLimits::scan('web_search', OpenAiLimits::FREE)['max_tool_calls'], OpenAiLimits::scan('web_search', OpenAiLimits::FREE)['max_output_tokens']]);
        $this->assertSame([3, 1600], [OpenAiLimits::scan('web_search', OpenAiLimits::PAID)['max_tool_calls'], OpenAiLimits::scan('web_search', OpenAiLimits::PAID)['max_output_tokens']]);

        config(['citevanta.ai_limits.scan.paid.max_tool_calls' => 4, 'citevanta.ai_limits.scan.free.max_output_tokens' => 900]);
        $this->assertSame(4, OpenAiLimits::scan('web_search', OpenAiLimits::PAID)['max_tool_calls']);
        $this->assertSame(900, OpenAiLimits::scan('web_search', OpenAiLimits::FREE)['max_output_tokens']);
        $this->assertSame(['effort' => 'low'], OpenAiLimits::scan('web_search', OpenAiLimits::FREE)['reasoning'], 'shared settings stay the same');
    }

    public function test_audits_and_trials_use_the_free_tier_and_paid_private_and_admin_scans_the_paid_tier(): void
    {
        $tier = fn (ScanRun $scan) => OpenAiLimits::tierFor(AiCallContext::forScan($scan)->bucket);

        $audit = ScanRun::factory()->for($this->scannableHotel(attributes: ['team_id' => null, 'is_audit' => true]))->create(['trigger' => ScanTrigger::Audit]);
        $trial = ScanRun::factory()->for($this->scannableHotel(plan: Plan::Trial, attributes: ['name' => 'Trial Resort']))->create(['trigger' => ScanTrigger::Setup]);
        $paid = ScanRun::factory()->for($this->scannableHotel(attributes: ['name' => 'Paid Resort']))->create(['trigger' => ScanTrigger::Manual]);
        $private = ScanRun::factory()->for($this->scannableHotel(plan: Plan::Private, attributes: ['name' => 'Private Resort']))->create(['trigger' => ScanTrigger::Scheduled]);
        $admin = ScanRun::factory()->for($paid->hotel)->create(['trigger' => ScanTrigger::Admin]);

        $this->assertSame(
            [OpenAiLimits::FREE, OpenAiLimits::FREE, OpenAiLimits::PAID, OpenAiLimits::PAID, OpenAiLimits::PAID],
            [$tier($audit), $tier($trial), $tier($paid), $tier($private), $tier($admin)],
        );
    }

    public function test_the_scan_call_sends_the_limits_of_its_tier_to_openai(): void
    {
        config(['citevanta.engine' => 'openai']);
        $sent = [];

        foreach ([['trial', 'Trial Resort'], ['starter', 'Paid Resort']] as [$plan, $name]) {
            $hotel = $this->scannableHotel(prompts: 1, plan: Plan::from($plan), attributes: ['name' => $name]);
            User::factory()->withTeam($hotel->team)->create();
            $scan = ScanRun::factory()->for($hotel)->create(['trigger' => ScanTrigger::Scheduled, 'prompt_ids' => [$hotel->prompts()->value('id')], 'runs_per_prompt' => 1, 'engine' => 'openai']);
            $client = new ClientFake([CreateResponse::fake(), CreateResponse::fake()]);
            $this->app->instance(ClientContract::class, $client);
            $this->app->forgetInstance(AnswerExtractor::class);

            try {
                app(ScanCallRunner::class)->run($scan, $hotel->prompts()->first(), 1, 'openai');
            } catch (Throwable) {
                // Only the answer request matters here; the fake analysis reply is not valid JSON.
            }

            $client->assertSent(Responses::class, function (string $method, array $parameters) use (&$sent, $plan) {
                if (isset($parameters['tools'])) {
                    $sent[$plan] = [$parameters['max_tool_calls'], $parameters['max_output_tokens']];
                }

                return isset($parameters['tools']);
            });
        }

        $this->assertSame(['trial' => [2, 1200], 'starter' => [3, 1600]], $sent);
    }
}
