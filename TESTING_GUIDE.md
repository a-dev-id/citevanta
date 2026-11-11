# CiteVanta local manual testing

Local application: http://nai.test (configured application URL). If needed, run `php artisan serve --host=127.0.0.1 --port=8000 --no-interaction` and use http://127.0.0.1:8000.

## Credentials and fixture safety

Run `php artisan testing:fixtures seed --no-interaction` in the local environment. The seeder is idempotent, marks only its own records, verifies dedicated test emails without sending email, and refuses production or unrelated email/slug collisions. Do not run the general DatabaseSeeder to reset these fixtures.

Passwords are generated independently with cryptographic randomness. Open the **gitignored, private** `storage/app/private/testing-credentials.json` locally for the ten login credentials. No API keys or production credentials are included in this guide or that document. Do not publish the credential file. Rerunning seed preserves passwords. Reset generates new passwords for recreated accounts; accounts referenced by unrelated records are preserved.

| Account | Email | Assigned organization | Role |
|---|---|---|---|
| Platform Administrator | platform-admin@example.test | Platform access to all organizations | Super Admin |
| Free Owner | free@example.test | CiteVanta Test — Free | Owner |
| Starter Owner | starter@example.test | CiteVanta Test — Starter | Owner |
| Starter Admin | starter-admin@example.test | CiteVanta Test — Starter | Administrator |
| Pro Owner | pro@example.test | CiteVanta Test — Pro | Owner |
| Pro Admin | pro-admin@example.test | CiteVanta Test — Pro | Administrator |
| Pro Viewer | pro-viewer@example.test | CiteVanta Test — Pro | Viewer |
| Agency Owner | agency@example.test | CiteVanta Test — Agency | Owner |
| Agency Admin | agency-admin@example.test | CiteVanta Test — Agency | Administrator |
| Agency Viewer | agency-viewer@example.test | CiteVanta Test — Agency | Viewer |

## Plans and workspace data

| Plan | Monthly price | Credits | Brands | Members |
|---|---:|---:|---:|---:|
| Free | $0.00 | 5 | 1 | 1 |
| Starter | $29.00 | 450 | 1 | 2 |
| Pro | $99.00 | 1,500 | 5 | 5 |
| Agency | $249.00 | 4,000 | 10 | 20 |

Each test organization has its own Nandini Jungle by Hanging Gardens brand, https://nandinibali.com, Hospitality / Luxury Resort, Ubud, Bali, Indonesia, aliases Nandini Jungle and Nandini Jungle Resort, and Australia / English as its saved default market. Duplicate websites across organizations are intentional. Each has 12 unbranded saved prompts and five competitor identities: Hanging Gardens of Bali, Padma Resort Ubud, Viceroy Bali, Capella Ubud, COMO Shambhala Estate. There is no separate saved-prompt or competitor cap in the existing catalog.

Each workspace contains 12 visibly simulated results: ranked/unranked recommendations, mention without recommendation, absent brand, official/third-party citations, competitors, failed search, and uncertain result. Paid-plan test organizations also have a simulated campaign. These are not provider observations or genuine AI visibility measurements. The preloaded demonstration results have no provider usage or financial ledger entries and consume zero monthly credits. New searches run through the local simulator and consume only the dedicated test organization’s simulated subscription credits. Only test-workspace simulations contribute to its demo dashboard; normal-workspace analytics exclude simulations. Demo workspaces cannot dispatch real provider requests, even if other controls are changed. The separate simulated provider performs no network calls.

## Manual checks

1. Sign in with each account. The workspace banner must say **SIMULATED TEST DATA**. Account and brand selectors must show only authorized organizations; the platform account can select all organizations from separate Platform administration. Verify each selected organization's plan form reflects that organization, not the last switcher option.
2. Open Dashboard, Test History, a saved result, and Competitor Analysis. Inspect recommended/not-recommended cases, rankings, and both citation types. All measurements are labelled simulated. Failed/uncertain fixtures must remain saved without provider retries.
3. Open Plan & Usage. Expect allowance 5 / 450 / 1,500 / 4,000, used 0, reserved 0, the full remaining balance, and the next monthly reset. Catalog prices are informational; no checkout exists.
4. Inspect the six navigation groups: Dashboard; Monitoring (Quick Test, Monitoring Campaigns, Search Prompts); Results (Test History, Competitor Analysis); Improvements; Workspace (Organizations & Brands, Team Members, Plan & Usage); Settings (AI Settings). No Cost Controls or internal expenses/usage units appear, including for platform administrators.
5. Free/Starter do not receive advanced improvement pages; Pro/Agency receive implemented recommendations. Scheduling, extra AI platforms, advanced analytics/market comparisons, exports and white-label features remain Not launched. Free has manual tests only and cannot create campaigns. Paid plans can preview campaigns using their own saved prompts/market. Previewing sends no request.
6. Owners can manage their own organization/team; organization administrators can manage permitted monitoring/brands but not platform settings, plans, team ownership, or entitlement limits. Viewers can read permitted dashboards/results but cannot access Quick Test or campaign editors, execute searches, or change records. Ordinary users cannot self-assign plans. Only platform administrators can set organization status and subscription.
7. Free already uses its one brand/member; Starter uses its one brand/two members. Attempt extra additions and expect server-side rejection. Pro/Agency permit additions only up to their catalog capacity. Downgrades that exceed capacity or committed credits must fail without resetting consumption.
8. Try direct URLs and requests: `/batch-settings` and `/pilot/enable` must return 404; ordinary accounts receive 403 for `/platform/organizations`. Another organization's result ID must return 404. Forged subscription changes must return 403. `/register` and payment/checkout routes remain absent.
9. Dedicated local test organizations can now execute the simulated workflow described below. Real execution remains locked. Never raise live controls or invoke live commands to test quotas. Free remains Quick Test only. Paid plans can start and process simulated campaigns. Automated tests cover credit reservation, consumption, cancellation, duplicate requests, exhaustion and reset.
10. Repeat at desktop/tablet/mobile widths (1920, 768, 390, 320). Tables scroll within their cards, controls remain usable, and pages have no horizontal overflow.

## Reset or remove only dedicated fixtures

- Add missing fixtures without deleting manual test edits: `php artisan testing:fixtures seed --no-interaction`.
- Recreate only marked test organizations/results/accounts: `php artisan testing:fixtures reset --confirm-test-fixtures --no-interaction`.
- Remove only marked test organizations/results and unused dedicated accounts: `php artisan testing:fixtures remove --confirm-test-fixtures --no-interaction`.

Never use `migrate:fresh`, `db:wipe`, or broad table deletion on the local MySQL database. These commands deliberately preserve the original Nandini workspace, pilot, customer subscriptions and historical results. Referenced test users are retained rather than deleting unrelated records.

## Automated validation and backend controls

Run `php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --colors=never`. Tests use in-memory SQLite and mocked provider responses, never the local customer database. Legacy response fixtures explicitly retain their original model; new monitoring uses the central GPT-6 Luna default. setting `$env:LOCAL_TEST_UI='1'` in PowerShell and running `node tests/Browser/local-testing-ui.cjs` runs read-only browser checks against the bounded local server at port 8026 using the private test credentials. No execution submissions are allowed.

Provider pricing, reconciliation, historical expenses, safety reservations and financial locks remain backend-only. SubscriptionFinancialProtection derives a conservative per-organization envelope from current plan credits, model weights and pricing, bounded by the secure deployment ceiling. Automatic organization limits require an explicit deployment setting; the existing legacy zero limits are retained. The independent deployment execution switch defaults to false, the global zero cap still blocks dispatch, and demo organizations always refuse real dispatch. No financial configuration is available through the application UI.

## Implementation file map

- Organization access and binding: `app/Http/Controllers/OrganizationController.php`, `AuthController.php`, `app/Http/Middleware/ResolveTenant.php`, `app/Services/TenantContext.php`, `app/Policies/OrganizationPolicy.php`, `resources/views/organizations/{details,index,switchers,usage,platform}.blade.php`.
- Credit-only UI: `app/Http/Controllers/{BatchController,PilotController}.php`, `routes/web.php`, `resources/views/layouts/{app,navigation}.blade.php`, `resources/views/batches/{balance,create,index,show}.blade.php`, `resources/views/{settings,tests/show,pilot/index,improvements/show}.blade.php`, shared result/error displays. The obsolete `resources/views/batches/settings.blade.php` was removed.
- Backend protection and simulation separation: `app/Services/{SubscriptionFinancialProtection,MonitoringMessages,CostControl,CreditLedger,OrganizationQuota,TestExecutor,VisibilityMetrics,PilotStudy}.php`, `app/Models/{Organization,User,TestRun}.php`, `app/Http/Middleware/SanitizeMonitoringErrors.php`, `bootstrap/app.php`, `config/{spending,local_testing}.php`, `.env.example`.
- Fixtures and lifecycle: `database/migrations/2026_11_11_060302_add_testing_fixture_markers.php`, `database/seeders/LocalTestingSeeder.php`, `app/Console/Commands/ManageTestingFixtures.php`, `.gitignore`.
- Validation: `tests/Feature/LocalTestingEnvironmentTest.php`, updated existing BatchTesting, ImprovementIntelligence, Navigation, OpenAiFailure, PilotStudy and TenantIsolation tests, `phpunit.xml`, `tests/Browser/local-testing-ui.cjs`.

## Complete simulated monitoring workflow

Local `.env` now has `LOCAL_SIMULATED_MONITORING=true`. This switch defaults to false in `.env.example` and is also guarded by the local/testing environment, dedicated fixture marker, and exact test-organization slug. It has no effect in production or in the original Nandini organization. `AI_EXECUTION_ENABLED` and live financial limits remain unchanged and locked.

1. Sign in as `starter@example.test`, `pro@example.test`, or `agency@example.test`. Verify the SIMULATED TEST DATA banner and current available credits under Plan & Usage.
2. Quick Test shows **Simulated monitoring (local testing)** and `simulated-monitoring-v1`. Select a prompt and Australia / English, then Run test. Expect a labelled simulated result, a new history entry, dashboard/competitor updates, and one used simulated credit. No real provider usage or expenses are recorded.
3. Open Monitoring Campaigns → New Campaign. Name it, select all 12 saved prompts, Australia / English, and one repetition. Preview requests and credits. Previewing consumes no credits.
4. Review the 12 required credits. Select both confirmation checkboxes. **Start Campaign** becomes enabled when enough credits are available. Starting reserves the credits without processing searches yet. An older unexecuted OpenAI-labelled draft in a dedicated test organization is converted to the simulator only when you explicitly start it; no duplicate campaign or prompts are created.
5. Click **Process Next Searches**. Each click processes up to three simulated searches. Watch Completed/Remaining, Search attempts, Used credits, and Reserved credits update. Four clicks finish a 12-search campaign; the reservation reaches zero, twelve credits are used, and the campaign becomes Completed.
6. Open saved responses, Test History, Dashboard and Competitor Analysis. Results are labelled simulated and use only workspace-specific brand, competitor, and citation fixtures. They are not genuine AI visibility observations.
7. For cancellation, create another campaign, start it, and process one group of three. Click **Stop Campaign**. The nine pending searches become Cancelled, their reserved credits are released, and the three completed searches retain their used credits.
8. Free can run Quick Test within its five monthly credits but cannot create/start campaigns. After five completed one-credit simulated searches, the sixth is rejected. Existing account rate limits still apply; wait for the limit window when necessary. Viewers cannot execute or stop searches. Organization administrators can operate campaigns but cannot change entitlements.
9. Repeated processing of a completed campaign or resubmission with the same submission ID cannot charge again. No live worker or recurring schedule is needed or enabled.

To restore initial simulated balances and results, use the dedicated fixture reset command above. It resets only the four marked testing organizations and may rotate their local passwords; the original Nandini workspace and pilot remain intact.
