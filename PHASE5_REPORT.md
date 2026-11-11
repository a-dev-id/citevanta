# CiteVanta Phase 5 implementation report

| Task | Before | After | Remarks |
|---|---|---|---|
| Organizations | Single customer | Multi-organization | Authenticated platform setup; existing administrator owns Nandini Jungle. |
| Workspaces | Single brand | Multiple brands | Organization and brand selectors; private data and identity registry per brand. |
| Authentication | Administrator only | Organization roles | Owner, Administrator, Viewer; membership checked on every request and API dispatch. |
| Data isolation | Single customer | Tenant-scoped | Query scopes, policies, route binding, input validation, and composite database foreign keys. |
| API usage | Application-level | Per organization | UTC monthly attempts, outcomes, recorded tokens, tool calls, known costs, and uncertain costs. |
| Subscription plans | Not available | Prepared | Configurable Free, Starter, Pro, Agency foundation limits; no commercial prices or subscriptions. |
| Billing | Inactive | Remains inactive | No payments, public registration, or recurring AI monitoring enabled. |

## Architecture and access

An organization owns memberships and monitored brands. Each customer-data row carries both `organization_id` and `monitored_brand_id`. A brand has a general business name, website, description, industry, country, verified aliases, active status, and a private competitor identity registry. It need not be a hotel: automated tests cover a coffee business, and browser tests cover coffee and retail brands.

`TenantContext` is request-scoped. HTTP middleware validates active organization membership and brand ownership before Laravel substitutes route models. Tenant models return no rows without a context, assign ownership from that validated context, guard ownership fields against mass assignment, reject ownership changes, and validate related records. Composite foreign keys additionally reject cross-organization and cross-brand parent links, including circular batch-item/result references. A foreign `/tests/{id}` or batch, prompt, citation, identity, recommendation, or brand URL cannot bypass the active context.

Owners manage organization details, team members, ownership transfer, brand profiles, and their organization API allowance. Administrators manage brands and operational data but cannot manage the team, change organization allowances, or transfer ownership. Viewers can read dashboards, reports, histories, and organization information; write routes and paid-request actions are denied server-side and hidden in the UI. Roles apply across the member's organization. Membership removal is checked on subsequent requests and before paid dispatch. Logout and authorized organization switching remain available after a prior context loses access.

The bootstrap administrator also has an explicit platform-administrator flag. Organization creation and application-wide Cost Controls remain restricted to that platform privilege. Platform privilege does not bypass membership or tenant record scopes. Foundation-plan assignment through Organization Settings additionally requires ownership; ordinary customer owners cannot self-upgrade entitlements. Existing-account passwords are never changed when that account is added to another organization.

Organization switches accept only memberships authorized for the authenticated user. Brand switches accept only active brands in that organization. Switching clears pending search/batch/explanation approval tokens and rotates the CSRF token. A legitimately deactivated selected brand falls back to another active brand in the same organization; a foreign brand ID is rejected. At least one active brand must remain.

## Isolation audit

The following tables are owned and scoped by organization **and** brand:

`search_prompts`, `market_scenarios`, `test_runs`, `ai_responses`, `citations`, `hotels`, `hotel_mentions`, `hotel_assessments`, `test_batches`, `batch_items`, `api_usages`, `cost_entries`, `improvements`, `improvement_events`, and `improvement_explanations`.

The legacy `hotels` table and historical `nandini_official` / `is_nandini` field names remain for compatibility. Runtime identity checks now mean the selected monitored brand, and the interface uses business terminology. Original records, classifications, and response content were not renamed or recreated. New brand identity registries and corrections are isolated instead of sharing a global hotel catalog. The old customer-specific runtime recognition configuration has been replaced by those scoped registries; legacy Nandini seed data remains only for initial setup/backfill.

Users are shared authentication identities, linked through the organization membership pivot. Providers are a shared capability catalog. `spending_settings` and `budget_months` remain application-wide protection/reconciliation infrastructure; their totals and notes are exposed only through platform Cost Controls. Customer batch pages show their organization's allowance and commitments. Deliberately unscoped queries are limited to that global guard, migration/backfill, the console dispatcher, and explicitly organization-filtered usage aggregates.

No customer export or file-attachment endpoints existed or were added. Private storage remains inaccessible without Laravel's storage authorization; the unsigned credentials-file URL is covered by a negative test. Shared public assets contain platform branding and framework files. Credentials remain in secure server configuration; `.env` was not changed. Logs identify organization/brand/request IDs without logging prompts or API credentials.

## Safe migration results

Applied locally:

1. `2026_11_10_060422_add_organization_foundation.php`: creates organizations, memberships, and brands; adds nullable ownership columns; transactionally backfills ownership through existing parent relationships.
2. `2026_11_10_060423_enforce_tenant_ownership.php`: verifies completion, then adds non-null ownership, indexes, brand ownership constraints, and composite parent constraints. Identity names and market names become unique within a brand instead of globally.

`TenantBackfill` is repeatable: it preserves existing ownership, checks counts and parent consistency, and does not re-add revoked memberships. Conflicting or incomplete ownership blocks enforcement. Rollback methods intentionally refuse to strip live ownership and isolation constraints; use a verified backup for a rollback deployment.

The local application was placed in maintenance mode for migration and returned to service afterward. A private pre-migration data snapshot was retained outside the public directory. Counts and hashes of **all original fields across 18 tables matched exactly immediately before and after migration**.

| Existing data | Preserved count |
|---|---:|
| Test runs / complete response records | 3 / 3 |
| Citations | 6 |
| Legacy mentions / assessments | 8 / 6 |
| Usage records / total tokens | 1 / 9,040 |
| Search prompts / market scenarios | 1 / 5 |
| Verified identities | 8 |
| Improvements / review events | 3 / 3 |
| Batches / batch items | 0 / 0 |
| AI explanations / cost entries | 0 / 0 |

The real database now has **1 organization, 1 monitored brand, and the existing administrator as Owner**. The additional organizations/users used in tests exist only in isolated test databases. Final verification found **0 unowned rows and 0 cross-workspace parent links**. Historical responses, raw metadata, API IDs, citations, test IDs, usage, improvement notes/history, and manual corrections remain intact. Local backfill replay preserved memberships as well.

## Usage and quotas

Organization Settings reports current brands/members and UTC monthly search attempts, successful/failed outcomes, explanation attempts, recorded input/output tokens, recorded web-search calls, known usage-cost estimates, and unknown/untracked cost counts. Usage includes all of the organization's brands and excludes other organizations. Costs are not invented when usage or pricing is missing. Provider/model/request details and versioned pricing snapshots remain linked through search/explanation records and cost entries for reconciliation.

`config/plans.php` defines configurable brand, member, monthly AI attempt, batch-size, repetition, provider, and retention settings. Invalid configuration fails closed. The migrated organization receives Agency foundation limits to preserve existing operational headroom; newly created organizations default to Free limits. These are internal entitlement configurations, not activated commercial subscriptions or advertised prices.

Brand/member creation and ownership-sensitive mutations use organization row locks. API reservations hold the existing global spending lock and an organization row lock, recheck creator permissions and brand activity, and enforce organization provider/request/spending quotas before creating a request or dispatching it. Attempts, retries, and optional AI explanations count toward the monthly AI quota; adding a brand does not create another organization allowance. Application-wide daily and spending protection remains in addition to organization limits. Earlier untracked Nandini spending still requires the existing platform reconciliation allowance; it is not silently treated as known zero spending.

**The real global application spending limit remains $0. The migrated organization's allowance is also $0. No live paid API request or batch was executed.**

## Cron and preserved functionality

The bounded command remains `php artisan intelligence:batches-process --limit=1`. Its console dispatcher inspects eligible work, validates organization/brand activity and submitting-member permissions, establishes the correct tenant context, runs the existing processor, and restores context afterward. Claims, retries, interruption handling, immediate persistence, global budget protection, and the original Phase 2 analysis engine are reused. A revoked submitter's old batch is paused and cannot starve an authorized batch in that brand.

No worker or cron schedule was installed or enabled. The legacy one-time live-test command now requires explicit organization, brand, user, and `--confirm-live`, and still obeys all spending guards. It was not run. The administrator bootstrap command assigns initial organization ownership on a fresh installation.

## Modified files

- New models/concern: `app/Models/Organization.php`, `MonitoredBrand.php`, `BelongsToWorkspace.php`. Ownership concern applied to all 15 customer models; `User.php` adds memberships/platform flag; `Hotel.php` adds a generic monitored-brand identity check with compatibility for legacy callers.
- New services: `app/Services/TenantContext.php`, `TenantBackfill.php`, `OrganizationQuota.php`. Updated `CurrentWorkspace`, `TestExecutor`, `BatchManager`, `BatchProcessor`, `CostControl`, `HotelRecognition`, `CitationAnalysis`, `VisibilityMetrics`, `ImprovementIntelligence`, and `ImprovementExplainer` to use selected-brand ownership or guarded accounting.
- Authorization/HTTP: `app/Policies/OrganizationPolicy.php`, `WorkspaceRecordPolicy.php`; `app/Http/Middleware/ResolveTenant.php`, `EnsureAdministrator.php`; `bootstrap/app.php`; `AppServiceProvider.php`; new `OrganizationController.php`; authentication and scoped input updates in `AuthController`, `TestController`, `BatchController`, and `VisibilityController`; `routes/web.php`.
- Database/setup: the two migrations above, `database/factories/OrganizationFactory.php`, `MonitoredBrandFactory.php`, updated `ImprovementFactory.php`, `DatabaseSeeder.php`, `CreateAdministrator.php`, and `RunFirstTest.php`.
- Configuration: new `config/plans.php`; customer-specific runtime values removed from `config/intelligence.php` and unused single-workspace selectors removed from `config/workspace.php`. No dependency changes.
- UI: new `resources/views/organizations/{index,create,brand-fields,switchers}.blade.php`; shared layout/login, dashboard, prompt/history/batch/improvement controls, settings links, visibility terminology, and shared result table. Native Tabler components and existing green branding remain. The empty-results state now remains readable on narrow screens.
- Tests: new `tests/Feature/TenantIsolationTest.php`, `tests/Browser/tenancy-ui.cjs`; legacy test fixtures now grant explicit organization membership; existing browser assertions use the new navigation/account labels. Pint also normalized formatting in touched application files without changing provider HTTP behavior.

## Validation

- **132 automated tests passed, 987 assertions**: all 94 Phase 1–4 tests plus 38 tenant/migration/quota tests.
- Tenant tests cover creation, roles, switching, forged sessions, direct/nested IDs, model and database ownership checks, all evidence children, two brands in one organization, two organizations' usage/quotas, team limits, ownership transfer/revocation, inactive brands, malformed plans, zero budgets, provider limits, and mocked multi-tenant cron execution.
- A dedicated replay creates the original Phase 1–4 schema in a separate in-memory SQLite connection, populates legacy IDs, Unicode response text, citations, manual corrections, batches/results, explanations, costs, and review history, then runs both new migrations. Original fields and IDs remain identical, and a second backfill preserves corrections and counts.
- **48 existing browser page checks** and **40 new owner/viewer page checks**, across 1920, 768, 390, and 320 pixel widths. The isolated new workflow covers organization/brand/member creation, both selectors, private prompt isolation, viewer restrictions, unauthorized original-result access, expanded forms, UTF-8, JavaScript errors, and horizontal overflow. Paid form submissions: **0**.
- Pint formatting and Blade compilation passed. API tests used mocks with stray requests prohibited. No production customer accounts, test responses, or paid usage were fabricated.

## Deployment and remaining limitations

For another deployment, take a verified database backup and enable maintenance mode before deploying tenancy-aware code and running `php artisan migrate --force`. Then rebuild configuration/Blade caches and return the application to service. The two migrations are already applied locally. The initial organization remains the existing Nandini customer, and new organizations are provisioned only through the authenticated platform setup interface.

No billing, signup, invitation emails, or recurring monitoring is enabled. Team credentials are provisioned manually; no messages were sent. Retention periods are configurable preparation only: no automatic archival or deletion runs, preserving historical data. Quotas apply across an organization's brands; per-brand membership roles are not introduced. Unknown provider outcomes and costs retain conservative reservations and are never automatically repeated. Existing pricing validity still requires review by its configured expiry date.

The legacy hotel-oriented table/class names remain compatibility storage; new organization/brand architecture and customer-facing terminology are general business concepts. Future export, attachment, provider, and billing features must use the same policies/context/ownership rules and add corresponding isolation tests.
