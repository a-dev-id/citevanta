# Phase 3 implementation report

Implemented batch configuration, previews, approval, bounded execution, progress, cancellation, and saved-result links using the existing Tabler layout. No paid API requests were made.

## Behavior

- A batch snapshots selected active prompts, AU/US/GB/ID market scenarios, repetitions, model, and versioned pricing. Request count is prompts × markets × repetitions. Draft submission is idempotent.
- Markets use the web-search tool's supported approximate country setting. English context keeps analysis comparable; the UI explicitly explains that this does not simulate real users.
- Monthly application budgets cover both single tests and batches. Integer USD micro-units, atomic reservations, a configurable safety reserve, request/attempt caps, and conservative input ceilings prevent unbudgeted dispatch. Unknown, expired, or changed pricing blocks execution.
- Usage-derived costs are estimates rather than provider invoices. Unknown outcomes retain their reservation. Overruns pause further batch work. Pre-existing usage requires an administrator-confirmed opening allowance instead of silently counting it as zero.
- Cron processing runs once and exits, with one request by default and a maximum of three per invocation. Durable claims, shared locks, and persisted attempt IDs prevent redispatch after uncertain outcomes. Only confirmed transient rate-limit rejections retry automatically, with backoff and a three-attempt ceiling.
- Cancellation stops pending work; an already dispatched request may finish. Responses are saved immediately and passed to the existing Phase 2 analysis service. Original responses and manual corrections are preserved.

## Modified files

- Database: `database/migrations/2026_11_10_045255_create_controlled_batches.php`, `database/seeders/BatchSetupSeeder.php`, and `DatabaseSeeder.php`.
- Models: `TestBatch`, `BatchItem`, `SpendingSetting`, `BudgetMonth`, `CostEntry`, and relationships on `TestRun`.
- Services: new `RequestPricing`, `CostControl`, `BatchManager`, `BatchProcessor`; integration changes in `TestExecutor` and `Ai/OpenAiProvider`.
- HTTP/CLI: `BatchController`, result loading in `TestController`, authenticated batch routes, and `ProcessTestBatches` command.
- UI: `resources/views/batches/*`, shared navigation/status badge, existing settings/result views, and `public/js/intelligence.js`.
- Configuration: `config/spending.php`, `.env.example`, and test-only budget in `phpunit.xml`. The actual `.env` and credentials were not changed.
- Tests: `tests/Feature/BatchTestingTest.php`, `tests/Browser/batches-ui.cjs`, and existing Tabler browser navigation assertions.

## Verification

- Full PHPUnit suite: **69 tests passed, 495 assertions**. API calls were mocked. Coverage includes generation, pricing arithmetic, monthly limits, cancellation races, retries, interrupted execution, duplicate prevention, snapshots, missing usage, manual corrections, and existing single-test/Phase 2 behavior.
- Browser workflow: **24 page checks passed** across 1920, 768, 390, and 320 pixel widths. Tested configuration, 16-request preview, large-batch acknowledgement, approval, monitoring, cost controls, and cancellation. Checked overflow, JavaScript errors, UTF-8, and SVG navigation. Used an isolated SQLite database and an invalid test API key; the processor was never run against it.
- Pint formatting and Blade compilation completed successfully.
- Actual MySQL audit: 3 existing tests, 1 usage record, 9,040 tokens, 0 batches, 0 cost entries. Test #3 answer, raw metadata, and citation URLs match the saved original fixture.

## Activation and remaining operational steps

The real monthly application limit remains **$0**, so new requests are blocked safely. In Cost Controls, set an approved monthly limit and confirm the current month's opening allowance after checking prior provider billing. No arbitrary budget was selected during implementation.

After reviewing a batch preview, approve it and configure a cPanel cron command using the host's actual PHP and application paths:

```sh
/path/to/php /path/to/nai/artisan intelligence:batches-process --limit=1
```

No cron, permanent worker, or recurring weekly automation was enabled. Deployments must run the migration and `BatchSetupSeeder`; both have already run in this local MySQL application. Use one application deployment with a shared database and lock-capable shared cache for every cron invocation.

Pricing version `openai-standard-usd-2026-11-10-v1` is valid through 2026-12-10 and must be reviewed before expiry. Current rates use standard gpt-4.1-mini pricing and the web-search call fee. Input usage already containing search tokens is not charged twice. Sources: [OpenAI pricing](https://developers.openai.com/api/docs/pricing), [model documentation](https://developers.openai.com/api/docs/models/gpt-4.1-mini), and [web search documentation](https://developers.openai.com/api/docs/guides/tools-web-search).

Live provider execution was deliberately not tested, as requested. Uncertain requests require provider-side review; they are never automatically repeated. Local analysis failures preserve the saved result and indicate that analysis needs retrying.
