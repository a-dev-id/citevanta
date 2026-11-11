# CiteVanta Phase 4 implementation report

| Task | Before | After | Remarks |
|---|---|---|---|
| Application branding | Nandini AI Intelligence | CiteVanta | Shared titles, sidebar, header, login, and footer use the platform identity. |
| Logo and favicon | Existing branding | CiteVanta assets | Both supplied PNG assets were found in `public/images/`. Sidebar uses the icon, login uses the wordmark, and favicon uses the icon. No replacement artwork was created. |
| Property identity | Nandini | Preserved | Current Workspace remains Nandini Jungle by Hanging Gardens. Hotel identities and historical test data were not rebranded. |
| Improvements | Not available | Implemented | `/improvements` generates recommendations from valid completed saved tests without API calls. Three recommendations were generated from the existing results. |
| Recommendation tracking | Not available | Implemented | Six review statuses, administrator notes, optional target URL, creation/completion dates, and append-only review events. |
| SaaS architecture | Single property | Future migration documented | Platform/workspace boundaries and recommendation scoping are in place; organization tenancy and subscriptions are not activated. |

## Implemented behavior

`config/branding.php` owns the platform name, description, tagline, and image paths. `config/workspace.php` identifies the monitored property and contains the previously verified page registry. The shared header shows the workspace on mobile and desktop. Existing credentials and authentication behavior are unchanged; the legacy API-key environment variable remains supported and the actual `.env` was not edited.

The administrator explicitly generates or refreshes recommendations from saved results. Rules reuse the existing visibility analysis, including manual hotel/citation corrections. Unresolved monitored-name matches are held for manual review rather than being labeled as confirmed absence. They detect absent brands, competing recommendations, missing official citations, context-only mentions worth checking for identity consistency, verified pages worth auditing against search intent, and repeated weaknesses across multiple prompts in the same current search category.

Every recommendation includes a category, priority, confidence, explanation, suggested action, saved prompt/version, competitor observations, original-answer excerpt, and links to the full test and analysis. Priority increases with repetition; high priority requires at least three observations across multiple prompts. Confidence is a heuristic about the investigation hypothesis, not a statistical estimate or a causal explanation. Repetitions do not represent independent consumer research.

No content defect, competitor advantage, publisher opportunity, or model-selection reason is asserted. Suggested improvements remain hypotheses. Only previously verified official pages are assigned automatically; administrator target URLs are labeled separately as unverified. Refreshing preserves review status, notes, completion history, manual corrections, and earlier AI-explanation snapshots. Conditions no longer observed become inactive rather than being deleted. Dashboard counts use tracked recommendations for the configured property.

The optional AI explanation requires a session-issued preview and explicit administrator approval. It reuses the OpenAI provider, model, pricing, execution lock, monthly budget, safety reservations, and cost ledger. It sends up to 20 saved evidence records with **no web search**. Structured output may select only existing test IDs and permitted action IDs; server validation rejects unsupported selections and additional fields. Displayed wording is assembled from those verified observations and action templates. This intentionally provides constrained explanation rather than unrestricted AI prose. Generated output, selected evidence, request settings, raw response, provider/model, and usage metadata are saved separately from search tests, so explanations never enter visibility denominators.

Unknown outcomes retain their cost reservation and are not automatically repeated. Explicit API rejections release reservations. Duplicate submissions return the persisted explanation. Corrected evidence or changed pricing invalidates an older approval. The real monthly limit remains **$0**; no paid request was executed and no real explanation usage was created.

## Modified files

- Branding/workspace: `config/branding.php`, `config/workspace.php`, `config/app.php`, `config/visibility.php`, `.env.example`, `app/Providers/AppServiceProvider.php`, and `app/Services/CurrentWorkspace.php`.
- Persistence: `database/migrations/2026_11_10_052820_create_improvement_intelligence.php`; models `Improvement`, `ImprovementEvent`, `ImprovementExplanation`; `database/factories/ImprovementFactory.php`.
- Application: `app/Services/ImprovementIntelligence.php`, `ImprovementExplainer.php`, `CostControl.php`, `RequestPricing.php`, `VisibilityMetrics.php`; controllers `ImprovementController.php`, `DashboardController.php`; `routes/web.php`.
- UI: `resources/views/improvements/index.blade.php`, `improvements/show.blade.php`, `layouts/app.blade.php`, `auth/login.blade.php`, `components/brand.blade.php`, `components/run-table.blade.php`, `dashboard.blade.php`, `settings.blade.php`, `tests/show.blade.php`, `batches/balance.blade.php`, `batches/settings.blade.php`, and `public/css/intelligence.css`.
- Tests: `tests/Feature/ImprovementIntelligenceTest.php`, `tests/Browser/improvements-ui.cjs`, and navigation assertions in the existing Tabler and batch browser tests.
- Supplied `public/images/citevanta-icon.png` and `citevanta-wordmark.png` are used unchanged. No package dependencies were added.

## Verification

- **94 automated tests passed, 716 assertions**, including all 69 Phase 1–3 tests and 25 new tests. API responses were mocked; unexpected HTTP requests were prohibited.
- Coverage includes branding on existing pages, PNG assets, valid-test filtering, ambiguous hotel separation, citation support, recommendation evidence, repeated-category thresholds, idempotent refreshes, review history, safe target URLs, workspace isolation, manual corrections, $0/insufficient budgets, expired prices, approval validation, duplicate requests, uncertain outcomes, constrained AI output, persisted usage, and immutable explanation snapshots.
- Existing browser suite: **48 page checks** across 1920, 768, 390, and 320 pixel widths, including login/logout, dashboard, Test History, search/prompt forms, and Phase 2 pages.
- New browser suite: **16 page checks and 16 status transitions** across the same widths, using an isolated SQLite fixture and invalid test API key. Verified both images, favicon HTTP loading, evidence links, disabled AI controls, UTF-8, SVG navigation, no horizontal overflow, and no JavaScript errors. No paid form submissions occurred.
- Pint formatting and Blade compilation passed.
- Migration applied successfully to local MySQL. Original-data audit hash was identical before and after: `978d1f602e3372bde84dd0386486fcff3fb5a8509171bbb14a53d201c1226cf6`. Audit covered historical runs, raw responses, usage, citations, hotel identities/mentions, and manual assessments. There remain 3 original tests and 9,040 tokens; no batches or API cost entries were added to the real database.

## Future SaaS migration and access-control plan

This phase remains one private, administrator-only installation. `CurrentWorkspace` is request-scoped and new recommendation reads/updates are restricted by the monitored hotel. It is not a production tenant resolver. Existing Phase 1–3 global queries are safe only within this single-customer administrative deployment and must be migrated before adding customer access.

1. Add `organizations`, membership/role records, and `monitored_brands`. A brand belongs to an organization; verified hotel identities can remain a shared reference catalog, while customer aliases, corrections, evidence, notes, and page registries must be scoped to their brand. Do not treat competitor identity as tenant ownership.
2. Add nullable organization/brand keys to prompts, tests, batches/items, derived analysis/citations, recommendations/events, explanation records, and usage/accounting records. Create the initial organization/brand for Nandini and backfill its existing rows without changing response text, prompt snapshots, provider IDs, or accounting history. Resolve links through the owning test/batch/brand rather than guessing from hotel mentions. Verify row counts, checksums, and foreign-key consistency before enforcing non-null ownership and composite tenant constraints.
3. Resolve the current organization and brand from the authenticated user's verified membership on every request. Add organization/brand policies and scoped route binding, including evidence links, review mutations, exports, and nested IDs. Replace existing unscoped source queries with mandatory tenant scopes. Never accept an organization ID or a hotel ID as authorization. Separate internal support privileges from customer administrators and audit support access.
4. Make provider usage a first-class ledger with organization, brand, provider, purpose, model, request ID, pricing version, currency, token/tool usage, committed reserve, and settled estimate. Preserve single-test versus explanation purpose and retain unknown costs. Keep application-wide/provider caps in addition to organization monthly caps, checking both atomically under row locks. Migrate the current ledger with reconciliation rather than resetting balances.
5. Introduce server-side subscription entitlements and effective-dated limit snapshots only in a later phase. Enforce provider access, monthly allowance, and batch/attempt limits in execution services as well as UI validation. Billing-provider webhooks must be verified and idempotent before they can change entitlements. No billing integration or subscription plans are enabled now.
6. Scope cache/lock names, storage paths, sessions, queued/cron job context, reports, and logs to organization/brand. Require the bounded cron dispatcher to reconstruct an authorized tenant context for every item; do not let a browser choose its execution scope. Keep credentials server-side and exclude raw customer evidence/secrets from shared operational logs.
7. Add two-organization/two-brand fixtures and negative tests for every list, direct-ID link, nested relation, update, evidence route, background execution, export, and budget reservation. Test cross-tenant IDs, concurrent budget claims, and storage/cache isolation. Complete the backfill and authorization audit, then enforce ownership constraints and deploy scoped code together before enabling any customer accounts or public access.

## Deployment and limitations

For another installation, deploy the code and supplied images, run `php artisan migrate --force`, then clear/rebuild configuration and Blade caches using the host's PHP version. Generate recommendations from the Improvements page. The migration is already applied locally. No new cron or scheduled task is required.

AI explanations remain unavailable while the monthly limit is zero or prior spending is unreconciled. Existing versioned pricing still expires on 2026-12-10 and must be reviewed before that date. Cost figures are application estimates rather than provider invoices. Live API execution was deliberately not tested. Provider billing review is required for interrupted outcomes; no recovery endpoint automatically resends them.

Evidence represents saved model responses, not a fresh website audit. Category grouping uses current prompt-category metadata and exact categories; it does not infer semantic relationships. The existing verified-page registry is small, so many recommendations correctly have no automatic target URL. Recommendation generation is manual and should be refreshed after evidence corrections. The application does not modify customer websites, activate subscriptions, expose public signups, implement production tenancy, or enable recurring API requests.

Implementation references: [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [Laravel Eloquent](https://laravel.com/docs/13.x/eloquent), [Laravel database transactions](https://laravel.com/docs/13.x/database), and [Laravel validation](https://laravel.com/docs/13.x/validation).
