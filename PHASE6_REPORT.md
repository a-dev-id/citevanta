# Phase 6 — Controlled AI Visibility Pilot

Implemented November 10, 2026. The Nandini pilot is prepared as a **disabled draft**. No paid requests were made. Global and organization monthly limits remain **$0**.

## Prepared configuration

- Existing Nandini organization and Nandini Jungle by Hanging Gardens brand.
- OpenAI Responses API with web search; existing configured `gpt-4.1-mini`.
- 12 prompts × one Australia (AU) approximate search-country setting × one repetition = **12 planned requests**.
- English response context. This is a supported approximate tool location, not a claim about real Australian users.
- Maximum 12 API attempts; no automatic retries. A failed or interrupted result pauses the pilot for review.

The exact prompts come from [Nandini AI Intelligence — Project Plan](https://docs.google.com/spreadsheets/d/1G185nyaUH7Eh_jc_i_r0R5lY3gmiNdkDpSvkyNzx0lg/edit#gid=113764491), `TRACKING SETUP!A18:D29`, read November 10, 2026. They are marked Proposed in the source and still require administrator review before execution. The source spreadsheet was not changed.

| ID | Category | Prompt |
| --- | --- | --- |
| P01 | Luxury | Best luxury resorts in Ubud Bali |
| P02 | Luxury | Best jungle resorts in Bali |
| P03 | Luxury | Best boutique resorts with rainforest views in Ubud |
| P04 | Romance | Best romantic resorts in Ubud for couples |
| P05 | Romance | Where to stay in Bali for a secluded honeymoon |
| P06 | Romance | Quiet luxury hotels in Ubud for couples |
| P07 | Wellness | Best wellness retreats in Ubud Bali |
| P08 | Wellness | Luxury jungle resorts in Bali with spa and yoga |
| P09 | Wellness | Best relaxing nature retreats near Ubud |
| P10 | Experiences | Best hotels near the Ayung River in Bali |
| P11 | Experiences | Where to stay in Ubud for jungle and river experiences |
| P12 | Experiences | Luxury hotels in Ubud with romantic dining experiences |

The existing historical prompt, “Best luxury jungle resorts in Ubud Bali,” remains unchanged. Preparation is idempotent and does not replace historical prompts or responses.

## Preview and spending controls

The current unexecuted draft shows:

| Measure | USD |
| --- | ---: |
| Estimated request cost, including search charges | $0.216436 |
| Search-tool charges included above | $0.120000 |
| Conservative reservation, including configured safety reserve | $0.978000 |
| Minimum whole-cent pilot ceiling | $0.98 |
| Observed pilot usage cost | None; zero requests executed |

Estimates are not observed costs. Existing versioned pricing expires December 10, 2026. Search-content tokens are included at the model input rate without adding them twice to recorded usage. Missing or expired prices block execution.

Approval is bound to the configuration, pricing, model, approver permissions, and UTC budget month. Global limits, organization limits and quotas, batch reservations, and pilot ceiling are checked again before dispatch. Generic batch start cannot bypass pilot approval. Enabling queues work; it does not call OpenAI from the approval page.

Existing bounded cron processing is reused, with no permanent worker or new recurring schedule. Duplicate prevention, saved immediate results, and interrupted-run handling remain in the existing batch processor. The unchanged daily application cap is 10 attempts per UTC day, so this 12-request pilot requires at least two UTC days. Other application traffic can extend that time. A cap pause requires administrator review and resume.

## Quality review and reporting

The existing recognition, recommendation, competitor, citation, visibility, and improvement services are reused. No second monitoring or analysis engine was introduced.

Administrators can approve or flag individual results, inspect original evidence, and see append-only review history. Approval checks the source prompt snapshot, saved answer against raw response text, response identity, citations and offsets, supported request location, and duplicate response identifiers. Missing citations, unknown identities, token gaps, and unknown costs are visible warnings. An integrity failure prevents approval. A later evidence or manual-analysis change makes the earlier review stale.

The final report denominator includes only valid completed latest results with a current approval. Failed, incomplete, invalid, flagged, unreviewed, and stale-reviewed results are excluded. Each rate displays its numerator and denominator. A separately labeled provisional column includes all valid completed results, including flagged ones, so review-selection effects remain visible. Average position uses only explicit ranked recommendations. Historical results outside this batch do not enter the pilot report.

Report sections show mentions, recommendations, official citations, competitor visibility, sample size, tokens, known costs, and unknown-cost counts. Original evidence remains accessible. Tracked improvement suggestions use the existing engine and are labeled hypotheses; pilot-scoped generation preserves earlier recommendations and manual corrections. Unapproved pilot results also stay outside the normal visibility dashboard calculations.

Owner/Admin permissions and organization/brand isolation are retained. Viewers can read but cannot prepare, approve budgets, review results, or generate tracked hypotheses. Composite database constraints prevent a review from being attached to a different batch or workspace.

## Modified files

New application files:

- `config/pilot.php`
- `database/migrations/2026_11_10_065026_add_controlled_pilot_review.php`
- `database/factories/PilotResultReviewFactory.php`
- `app/Models/PilotResultReview.php`
- `app/Services/PilotStudy.php`
- `app/Services/PilotQuality.php`
- `app/Http/Controllers/PilotController.php`
- `resources/views/pilot/index.blade.php`

Updated application files:

- `routes/web.php`
- `app/Models/TestBatch.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/TenantBackfill.php`
- `app/Services/BatchManager.php`
- `app/Services/BatchProcessor.php`
- `app/Services/CostControl.php`
- `app/Services/VisibilityMetrics.php`
- `app/Services/ImprovementIntelligence.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/batches/show.blade.php`
- `resources/views/dashboard.blade.php`

Tests and documentation:

- `tests/Feature/PilotStudyTest.php`
- `tests/Browser/pilot-ui.cjs`
- `tests/Browser/prepare-pilot-fixture.php`
- Existing navigation-count checks in `tests/Browser/tabler-ui.cjs`, `batches-ui.cjs`, and `improvements-ui.cjs`.
- `PHASE6_REPORT.md`

## Verification

- **165 automated tests passed, 1,306 assertions**, including 33 new pilot tests with 319 assertions.
- Covered generation and idempotence, source snapshots, request counts, pricing/search charges, missing/expired prices, global and organization limits, quotas, explicit approval, bypass prevention, cancellation, duplicate prevention, rate limits, timeout/interruption recovery, twelve-request ceiling, tenant/role isolation, composite constraints, evidence integrity, ambiguous hotel identities, citations, manual correction/stale reviews, denominators, scoped hypotheses, and mocked CLI processing.
- Browser verification: **48 existing Tabler page checks, 8 draft-pilot checks, and 12 quality-pilot checks** at 1920, 768, 390, and 320 pixels. Checked preview, batch details, approval/flagging, evidence links, report calculations, and improvement generation. Paid-submission count: zero.
- Quality browser tests used an isolated SQLite database, invalid API key, prevented stray HTTP requests, and mocked responses. Fixture results and budgets were never inserted into the application MySQL database.
- PHP formatting and Blade compilation checked.
- Applied additive migration and prepared the real MySQL draft. Preservation audit confirms every pre-existing row is unchanged: 3 historical tests, 9,040 tokens, 6 saved citations. Real pilot: 12 pending items, 0 attempts, 0 reviews, 0 approved sample. Both monthly limits remain zero; execution is disabled.

## Outstanding checks and safe activation

There are no live pilot findings yet. Billing, credentials, project model access, and real provider availability were intentionally not probed. Small samples and one country context cannot establish population-wide visibility or explain why a model chose a competitor.

The [official deprecation schedule](https://developers.openai.com/api/docs/deprecations) lists retirement of the configured GPT-4.1 mini snapshot on January 14, 2027. Review model availability and pricing before activation. This phase does not switch the model automatically.

Only after an administrator explicitly authorizes spending:

1. Open **Visibility Pilot** in the Nandini workspace. Review all 12 source prompts, approximate country setting, request count, quality rules, and daily-cap limitation.
2. Reconcile prior provider spending and verify billing and model access. Existing historical usage without a cost entry must not be assumed free. Review current [pricing](https://developers.openai.com/api/docs/pricing) and [supported search location settings](https://developers.openai.com/api/docs/guides/tools-web-search).
3. Configure separately approved global and organization monthly allowances that cover existing commitments plus the displayed remaining reservation. **This implementation has left both at $0.** Refresh an unexecuted draft if pricing changes or expires. An attempted pilot cannot silently be repriced.
4. Enter a pilot ceiling covering the current displayed requirement ($0.98 for this untouched draft), acknowledge the plan and paid execution explicitly, then enable. Changed configuration requires fresh approval. Preparation and preview alone never authorize spending.
5. Use the existing bounded cPanel cron/manual command with the host's PHP and application paths: `php artisan intelligence:batches-process --limit=1`. Do not install a permanent worker or weekly automation. Review and resume after any cap or failure pause. Use Batch details to cancel pending work.
6. Inspect original evidence and approve or flag each result. Review final denominators and actual/unknown usage costs, then generate evidence-linked improvement hypotheses when useful. No automatic extra retries or replacement requests extend the twelve-attempt ceiling.

Stop point reached: prepared, previewed, and validated without live paid execution.
