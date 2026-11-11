# Phase 7 — Live pilot preflight and approval checkpoint

Reviewed November 10, 2026 (Asia/Singapore). **No paid requests submitted.** The existing pilot remains a disabled draft. Global and Nandini organization spending limits remain $0. This is a preflight report; live results and cost validation remain pending explicit administrator approval.

| Task | Status | Results | Remarks |
| --- | --- | --- | --- |
| Model verification | Documentation and account listing verified | Preferred identifier `gpt-6-luna` supports Responses and web search; authenticated read-only model listing returned HTTP 200 and includes it | Current configuration and existing batch still use `gpt-4.1-mini`; no silent switch |
| Pricing verification | Verified and prepared | Luna standard input $0.10, cached input $0.01, output $0.50 per million tokens; 30-minute/12-hour cache writes $0.125/$0.25 per million | Version v2 includes Luna; web search $0.01/call plus variable search-content tokens |
| Billing access | Unconfirmed | Existing credential authenticates for model listing | Model listing cannot prove billing balance, paid tool permissions, or successful generation; no paid billing probe submitted |
| Pilot configuration | Passed | One existing pilot, 12 distinct active unbranded prompts owned by Nandini, AU approximate tool country, English, one repetition, 12 pending items | No duplicate prompts or batches created |
| Spending controls | Correctly blocking | Global $0; organization $0; monthly opening allowance unreconciled | Proposed $2 global / $1.50 organization limits are not approved or applied |
| First live test | Awaiting administrator approval | Zero submitted | Mandatory first-attempt pause and separate continuation approval implemented |
| Remaining pilot tests | Not started | Eleven planned after the first | No recurring monitoring enabled |
| Visibility analysis | Ready, no live pilot sample | Existing analysis and quality-review engines retained | No pilot visibility percentages, competitor winners, or opportunities asserted |
| Cost reconciliation | Historical gap identified; pilot pending | No pilot reservations, usage, or expenditure | Historical usage needs an administrator-confirmed opening allowance |

## Evidence and current configuration

Read `PHASE6_REPORT.md`, existing pilot, batch processor, cost controls, quota enforcement, provider adapter, request pricing, and database records before making changes. PHP 8.3 and Composer are available; Boost remains installed. No dependencies were changed. After read-only inspection, added the pricing/reconciliation preparation and first-result approval barrier described below.

The organization is Nandini Jungle, monitored brand Nandini Jungle by Hanging Gardens, on the existing Agency entitlement: 10,000 monthly attempts, batch size 1,000, OpenAI allowed. Three historical attempts count this month. The global per-batch cap is 20; this pilot is restricted to 12 total attempts and one attempt per item. The existing daily application cap is 10 UTC-day attempts. It is unchanged: a full twelve-request pilot spans at least two UTC days, with other traffic potentially extending that time.

The existing bounded command is `php artisan intelligence:batches-process --limit=1`; its configured maximum is three requests per invocation. No processor was run against the real pilot, and no cron schedule was installed or enabled. Existing approval, duplicate prevention, failure pauses, immediate persistence, and cancellation controls remain in place. The first pilot attempt now automatically pauses the batch and clears execution approval. Further requests require a settled first-result cost, a current quality approval, and a separate explicit budget/execution approval. A failed first attempt cannot be approved into continuation. This barrier also prevents later cron invocations or generic batch resume from bypassing the checkpoint.

Current preview passes pricing, quotas, source/batch configuration, and provider configuration. Both spending checks block execution. The November opening allowance is null, with historical results through ID 3 awaiting reconciliation. There are zero cost entries or committed reservations; this does **not** establish zero prior provider expenditure.

Historical test #3 records 8,528 input and 512 output tokens, one web-search call, and six citations. Existing pricing derives $0.014232 in rounded estimated charges; with the application's 25% safety reserve this known component requires at least $0.017790. Failed historical attempts have no recorded token usage. These figures are not a provider invoice or a complete account-spending reconciliation. The administrator must confirm an opening allowance covering actual prior spending and any uncertainty; this report has not set one.

## Official model and pricing verification

[GPT-6 Luna documentation](https://developers.openai.com/api/docs/models/gpt-6-luna) establishes the exact identifier, Responses/web-search support, standard token rates, and cache-write pricing. The [official deprecation schedule](https://developers.openai.com/api/docs/deprecations) does not list Luna for imminent retirement; it lists the current GPT-4.1 mini snapshot for January 14, 2027 retirement and recommends Luna. Account model listing also includes both identifiers, but inference/tool permissions still require the approved first request.

[Official pricing](https://developers.openai.com/api/docs/pricing) confirms web-search call charges and model-rate search-content billing. The fixed 8,000-token search block applies to GPT-4.1 mini, not Luna. No Batch API discount is assumed: the application's batch processor sends ordinary Responses requests.

The current model's rates match [GPT-4.1 mini documentation](https://developers.openai.com/api/docs/models/gpt-4.1-mini): $0.40 input, $0.10 cached input, $1.60 output per million tokens. These rates are retained. New pricing version `openai-standard-usd-2026-11-10-v2` adds Luna and verified cache-write rates, valid through December 10, 2026. The same unexecuted draft was refreshed to v2; no new batch was created and execution remains disabled.

[Official prompt-cache accounting](https://developers.openai.com/api/docs/guides/prompt-caching) now informs cost reconciliation: ordinary input, cache reads, 30-minute writes, 12-hour writes, and output are counted once at their respective rates. Reasoning tokens are already included in output usage and are not added twice. Missing or inconsistent Luna write usage leaves costs uncertain and retains the reservation, causing the existing processor to pause. Unpriced writes also fail closed. Historical mini usage without the newer write fields remains compatible.

## Recalculated preview and approval proposal

| Calculation | Current saved GPT-4.1 mini draft | Prepared Luna calculation |
| --- | ---: | ---: |
| Planned requests | 12 | 12 |
| Included search-call fees | $0.120000 | $0.120000 |
| Estimated total | $0.216436 | $0.150139 |
| Conservative reservation | $0.978000 | $0.648756 |

Luna figures use the prepared v2 rates: 8,000 search-content tokens as a planning assumption, 2,500 output tokens, the existing 128,000-input reservation assumption, and 25% safety. The estimate conservatively prices estimated input at the 30-minute write rate; the reservation covers the higher 12-hour write rate. Minimum whole-cent pilot ceiling: **$0.65**. **These are estimates, not a provider-enforced absolute token/spend guarantee.** Actual variable search content can exceed assumptions; unknown usage or an overrun pauses progression. The configured model and existing draft remain GPT-4.1 mini until the administrator confirms using Luna with the live budget. Historical snapshots were not rewritten.

Recommended next stage: confirm using Luna, update the model configuration and the same unexecuted draft transactionally, and verify its final displayed reservation before applying approved budgets. No additional pricing implementation is pending. Retain the request ceiling of 12, one-request first stage, and separate approval for the remaining eleven. Proposed monthly caps are $2.00 global and $1.50 Nandini, with a $0.65 pilot ceiling at current rates. The administrator must also confirm the prior-month-to-date opening allowance; available budget is the approved limit minus that allowance and all committed reservations. If the confirmed opening allowance leaves insufficient room, activation stays blocked.

## Verification completed

- Final complete automated suite: **173 tests passed, 1,345 assertions**. This includes the 33 Phase 6 pilot tests and eight new Luna/first-stage control tests. Covers budgets, explicit approval, quotas, tenant/role isolation, duplicates, interrupted runs, failures, source/response/citation integrity, identity ambiguity, ranking and denominator rules. PHP formatting and Blade compilation passed.
- Existing Tabler browser suite: **48 page checks passed** at 1920, 768, 390, and 320 pixels.
- Additional read-only pilot browser checks: **4 passed**, confirming its saved estimate, disabled paid-execution button, and responsive layout at the same widths.
- Database preservation comparison: every pre-existing row matches the Phase 6 baseline. Three historical tests remain; one pilot batch, 12 pending items, zero pilot attempts. Both monthly limits remain zero.
- Credentials were never printed. Account access was checked only with authenticated read-only `GET /v1/models`, using application TLS verification. The restricted environment initially blocked outbound connectivity; the authorized network check subsequently returned HTTP 200. No generation request was made.
- Added `tests/Feature/LunaPricingTest.php`: eight mocked tests cover variable search-token billing, cache writes and reads counted once, missing/malformed usage, reasoning output, unchanged historical pricing, reservations, one saved/reconciled Luna response, first-attempt pause even with a three-request invocation, blocked generic continuation, separate quality/continuation approval, and first-request billing failure stopping all remaining work.
- Modified files: `config/spending.php`, `config/pilot.php`, `app/Services/RequestPricing.php`, `app/Services/PilotStudy.php`, `app/Services/BatchProcessor.php`, `resources/views/pilot/index.blade.php`, `tests/Feature/PilotStudyTest.php`, `tests/Feature/LunaPricingTest.php`, and `PHASE7_REPORT.md`. The existing Phase 6 suite explicitly retains its pre-Phase-7 execution mode; the new tests exercise the mandatory gate enabled in the application. No `.env`, credentials, authentication, subscriptions, or historical results were changed. Browser artifacts are local verification outputs.

## Live report and profitability status

Pilot submitted/successful/failed requests: **0 / 0 / 0**. Pilot input/output tokens and web-search calls: zero. Observed pilot charges, average cost per completed pilot request, and reserved-versus-reconciled expenditure are unavailable until execution. Current available application budget is $0, with an additional unresolved historical opening allowance.

There is no live pilot sample, so no mention rate, recommendation rate, citation rate, ranking, most frequent competitor, or evidence-backed pilot improvement opportunity is reported. The existing reporting and improvement modules will be used after approved execution and quality review. Model-selection explanations will not be invented.

The proposed subscription allowances imply revenue per included credit of $0.3333 Starter ($50/150), $0.20 Pro ($150/750), and $0.15 Agency ($300/2,000). These differ from the current internal plan quotas; neither quotas nor prices were changed. Profitability cannot be validated from an unexecuted pilot or token prices alone. After execution, compare observed search cost, failure/uncertainty reserves, caching, and repeated samples against those unit revenues, then include hosting, support, analysis, payments, taxes, and margin targets before adopting allowances.

## Administrator decisions required

1. Confirm selecting `gpt-6-luna` for the prepared pilot. Its pricing/reconciliation implementation is ready and tested with mocks; no paid execution has been enabled.
2. Explicitly approve raising the global cap to $2.00 and Nandini cap to $1.50, a $0.65 pilot ceiling subject to the unchanged final preview, and **one initial paid search only**. Confirm the historical November opening allowance using provider billing records. These approvals have not been inferred from the proposed figures in the brief.
3. After the first result passes request, actual web-search, saved evidence, identities/ranking, usage/cost, and Test History validation, approve continuation separately. Any critical failure stops progression. Leave recurring monitoring disabled.

This checkpoint follows the Phase 7 brief's explicit budget and first-test approval requirements. No live stage has been represented as complete.
