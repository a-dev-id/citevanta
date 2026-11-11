# Subscription plans and credit accounting

Implemented November 10, 2026. Payment processing, public registration, automatic top-ups, and recurring monitoring remain disabled. No real OpenAI searches or paid API requests were executed.

## Approved catalog

| Plan | Monthly price | Monthly credits | Active brands | Members |
| --- | ---: | ---: | ---: | ---: |
| Free | $0.00 | 5 | 1 | 1 |
| Starter | $29.00 | 450 | 1 | 2 |
| Pro | $99.00 | 1,500 | 5 | 5 |
| Agency | $249.00 | 4,000 | 10 | 20 |

`config/plans.php` holds catalog version, prices in cents, credit allowances, capacities, platform limits, feature entitlements, operational feature flags, and positive integer provider/model credit weights. The existing `monthly_searches` configuration key now represents the credit allowance for compatibility with existing quota consumers. One standard response uses one credit. Pro permits up to five supported platforms; Agency permits all supported platforms. Only the implemented, enabled OpenAI adapter is selectable. Listing an unimplemented provider in configuration does not expose it.

## Accounting and lifecycle

- Credits are shared across all brands in an organization. Monthly periods run from the first day of a UTC calendar month to the first day of the next month. Balances show allowance, used, reserved, remaining, and next reset. Period creation is lazy and requires no permanent worker or reset cron. Unused credits do not roll over.
- Existing current-period completed responses are represented in the opening credit ledger without changing their original searches, responses, citations, or usage rows. Failed historical tests are not treated as completed responses. Previously running/interrupted work retains a reservation.
- Single requests reserve credits in the same transaction as the existing financial admission and saved request. Campaign approval reserves pending responses atomically inside the existing batch transaction. Organization/user row locks serialize admission; operation uniqueness prevents duplicate credit charges. Model weights are recorded on each reservation.
- Completed responses consume their reservation. Confirmed provider rejections and known failed/incomplete responses release it. Unknown provider outcomes retain a reservation for review. Retries reuse the logical campaign-item operation instead of adding another successful-response charge.
- Cancellation releases undispatched reservations. A claimed request cancelled before persistence is refunded, including recovery after a process interruption. A persisted potentially dispatched request retains its reservation even if an item has a stale cancelled state. Completed/in-flight work is not automatically refunded.
- Dollar spending protection remains independent and mandatory. Global and organization checks run before request admission; campaign approval also checks organization funds before reserving credits. Available credits never enable execution against a zero or unreconciled dollar allowance.
- Active-period terms are snapshotted. Catalog edits do not rewrite existing period terms or historical usage. Explicit authorized plan changes are audited with before/after snapshots. Upgrades change the allowance without resetting consumption. Downgrades are rejected when active brands, members, or used/reserved credits exceed the target. Inactive brand history remains intact, and reactivation checks capacity. No proration, invoice, or payment is generated.

## Permissions and Free protection

Free retains manual tests, basic dashboard, competitor, and citation results. Campaign execution and advanced improvement recommendations are denied server-side when not entitled. Free and Starter do not receive advanced improvement pages; Pro and Agency do. Unfinished features are labeled **Not launched**, not presented as working tools.

Free API use requires verified email. Authenticated signed verification routes and a throttled resend action are implemented. Request limits apply per account across entry points (Free: three/minute; paid: thirty/minute, configurable), in addition to existing HTTP execution throttles. A verified account cannot multiply its Free allowance by joining multiple Free organizations. Public registration remains absent; organization creation and member provisioning remain authorized and throttled. Brand/member limits are checked server-side and reflected in creation controls. Viewer roles cannot execute requests or assign plans. Plan assignment requires an authorized platform administrator.

## Models and migration

Applied additive migration:

- `database/migrations/2026_11_10_081802_create_subscription_credit_accounting.php`

New models/tables:

- `CreditPeriod` / `credit_periods`: organization period boundaries, original plan snapshot, and allowance.
- `CreditEntry` / `credit_entries`: logical operations, weighted credits, request/item references, and reservation state.
- `PlanChange` / `plan_changes`: administrator and before/after entitlement snapshots.

Foreign keys and unique constraints protect period ownership and operation uniqueness. Rollback refuses to remove saved credit history. `User` now implements Laravel's email-verification contract. Existing search/result schemas are unchanged.

Main service changes: new `PlanCatalog` and `CreditLedger`; integration with `OrganizationQuota`, `CostControl`, `BatchManager`, `BatchProcessor`, and `PilotStudy`. Improvement service and middleware gates enforce feature access. Existing provider execution, recognition, visibility analysis, and cost formulas are reused. `RequestPricing::dollars()` now formats displayed USD amounts to two decimals; stored financial precision remains unchanged.

## UI changes

The existing six-group Tabler navigation and CiteVanta branding are preserved. Updated Dashboard, Quick Test, Monitoring Campaigns, campaign setup/details, pilot report, organization details/brands/team, Plan & Usage, and optional improvement explanation views.

Plan & Usage shows current terms, monthly prices, credit progress, reset date, brand/member capacities, available platforms, and informational upgrade/feature comparisons. Quick Test and campaign setup show required credits. Quick Test is disabled when spending or verification protection blocks it. Customer views hide internal cost estimates, token pricing, dollar reservations, and financial exposure; platform administrators retain the existing financial views. New verification notice uses the shared layout. No checkout, annual pricing, frontend framework, or decorative components were introduced.

## Validation

FINAL_AUTOMATED_RESULT

- Browser validation: **416 checks passed** at 1920, 768, 390, and 320 pixels. This includes 92 new checks across all four plans and 324 existing Tabler, navigation, campaign, improvement, tenancy, and pilot checks. Owner/viewer permissions, capacity controls, customer financial redaction, progress displays, and disabled execution were checked. Browser writes used isolated SQLite fixtures and invalid API keys.
- A dedicated MySQL schema tested two simultaneous PHP workers competing for five credits with four-credit requests. Exactly one reservation succeeded; one was rejected. Final balance: four reserved, one remaining, one ledger entry. No API requests. The temporary schema was removed.
- PHP formatting and Blade compilation verified. Mocked tests cover signed email verification, account abuse protection, cross-brand sharing, organization isolation, provider weights, cancellation/recovery races, retries, reset, term snapshots, downgrade protections, feature permissions, and both financial caps.

### Preserved application state

The preservation audit confirms all pre-existing application rows remain unchanged; runtime caches/session rows are excluded. The three historical searches, original responses, six citations, usage records, and manual corrections are retained. No new production test was submitted.

Nandini remains Agency: **4,000 allowed, 1 used, 0 reserved, 3,999 remaining**. The used credit represents the already saved completed test #3. Next reset: **December 1, 2026 at 00:00 UTC**. Its twelve-request pilot remains a disabled draft with zero attempts.

**Global spending limit remains $0.00. All organization spending limits are unchanged** (Nandini remains $0.00). Financial reservations retain full internal precision. Subscription credits do not override these restrictions.

## Outstanding operational setup

- The current mailer is `log`. Configure a real mail transport before offering Free email verification to customers. No real verification messages were sent during implementation.
- Scheduled monitoring, additional platforms, advanced analytics/market comparisons, exports, and white-label reporting are not launched. Their entitlements are configurable, but operational access remains disabled. Only existing implemented features are enabled.
- Unknown request outcomes require review; their credit and financial reservations are not automatically released. Existing daily application limits also continue to apply.
- Prices are informational internal entitlements. Payment processing, public registration, annual pricing, automatic top-ups, and recurring monitoring remain disabled.
