# Release 1.6.0 (24 Nov 2026)

Two zips, in `releases/`. **Each one is uploaded into its own target folder and extracted there**, so nothing can land in the wrong place:

| Zip | Open this folder in File Manager | What is inside (at the zip root, no wrapper folder) |
| --- | --- | --- |
| `citevanta-app-1.6.0-{timestamp}.zip` | `/home/balj5948/citevanta` | the whole app, `vendor/` without dev packages, `public/build/`, `release.json` and this file |
| `citevanta-public-1.6.0-{timestamp}.zip` | `/home/balj5948/public_html/citevanta.a-dashboard.com` | `build/`, `images/` and the favicon files |

Not in the zips, so the live ones stay as they are: `.env`, everything in `storage/`, and in the document root the `index.php` (your `usePublicPath` edit) and `.htaccess` (unchanged since 18 Nov).

`robots.txt`, `llms.txt`, `sitemap.xml` and `site.webmanifest` are **not files**: the app generates them (so the name and URLs always come from `.env`). A static `robots.txt` or `llms.txt` in `public_html` would hide the app's version: delete one if it exists.

Every build writes a new `release.json` (`id`, `version`, `build_date`, `built_at`) into the app zip, and the build prints the same **release id**. That id is how you prove the upload worked (step 6).

---

## What changed since the release that was live on 20 Nov 2026 (Milestone 3)

**Public website (English and `/id`)**
- Home, Pricing, Free audit, Contact, Privacy and Terms (the legal texts are drafts for legal review), with sitemap, robots, llms.txt, JSON-LD and hreflang.

**Free AI visibility audit**
- Form on the website, report at `/audit/{token}` (not indexed), report email, a 7-day reuse of the same hotel, Admin > Audits with CSV export.
- Waitlist ("email me when slots open"), 5 new audits a day, Cloudflare Turnstile on the audit, contact and registration forms (off until you add the keys).

**Billing**
- Manual bank transfer (active): invoices with a unique amount, "I have paid" with a private proof upload, Admin > Billing ("Mark as paid" / "Reject"), renewal invoices, reminders, past due, expiry to read-only, upgrades, cancel and resume, Admin > Teams (MRR, extend trial, comp plan).
- Xendit provider is built and tested but **off** (`BILLING_PROVIDER=manual`).
- Settings > Billing and the printable invoice page.

**Email**
- Weekly report (Monday 08:00 in each team's timezone), billing and audit emails in one layout, mail limited to `MAIL_MAX_PER_HOUR` per hour.

**Cost protection**
- Real cost of every AI call recorded (tokens and each web search), monthly budgets (total, audits, trials, admin), per-team cost cap, pause instead of fail, OpenAI "no credit" stop with an admin email and banner, runaway-scan guard. Admin > System shows spent / cap / remaining and "Resume paused jobs".
- Scan limits per answer, separate for free (audits, trials) and paid (paying and Private plan): free 2 searches / 1200 tokens, paid 3 searches / 1600 tokens.

**Trial lite**
- 7 days, 15 prompts, 1 run, ChatGPT only, 1 language, 0 manual scans, 2 content pieces; top 3 recommendations and accuracy issues in full, the rest locked.
- A trial from a free audit starts with the audit's answers; one trial per hotel, email must be verified, disposable email domains blocked, 2 trials per IP per 30 days.
- **New trials wait for your approval** in Admin > Teams (`TRIAL_REQUIRES_APPROVAL=true`).

**Private plan**
- Per-team price and limits (Admin > Teams > "Private plan…"), the hotel's own OpenAI key (Settings > AI key, with its own monthly budget), Google connection through the agent account (Admin > Integrations > Google, Settings > Connect Google), 13 months of GA4 and 16 months of Search Console, tracking health check.

**Brand and release tools**
- New logo and a full favicon set: `favicon.ico` (16/32/48), `favicon.svg`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, and `/site.webmanifest`. Links carry `?v=` (`APP_ASSET_VERSION`) so browsers fetch the new icon.
- The version and build date show in the admin footer and on Admin > System, with the release id from `release.json`.
- Admin > System now opens before the new database tables exist, so "Run deploy tasks" can always be reached.
- Two release zips (app and public), each extracted inside its own target folder; a fresh `release.json` per build, with a check on Admin > System that it matches the running version.

---

## New migrations (8, run by "Run deploy tasks")

1. `2026_11_20_005620_create_audits_table`
2. `2026_11_20_031010_add_billing_columns_to_teams_table`
3. `2026_11_20_031011_create_invoices_table`
4. `2026_11_20_031012_create_xendit_events_table`
5. `2026_11_20_031013_add_weekly_report_columns`
6. `2026_11_20_044243_add_trial_protection_columns`
7. `2026_11_20_044937_create_ai_budget_tables`
8. `2026_11_20_054006_create_private_plan_and_google_tables`

All only add tables and columns; nothing is deleted. Teams that are on a paid plan today become "active" without an end date.

---

## New `.env` keys

All are in `.env.production.example`. Copy the new lines into the live `.env`.

**Give real values on live**

| Key | What to do |
| --- | --- |
| `AI_BUDGET_TOTAL`, `AI_BUDGET_AUDITS`, `AI_BUDGET_TRIALS`, `AI_BUDGET_ADMIN` | Defaults 70 / 10 / 10 / 10 (USD per month). Set the total **below your OpenAI credit**. |
| `CITEVANTA_ANALYSIS_REASONING_EFFORT` | **Set to `none`.** Your analysis model `gpt-6-luna` does not accept the default (`minimal`); without this line every analysis step of a scan fails ("Completed with errors"). |
| `TRIAL_REQUIRES_APPROVAL` | `true` (default): every new trial waits for you in Admin > Teams. Use `false` to let trials start by themselves. |
| `MAIL_MAX_PER_HOUR` | 100 by default; set it to the mail limit of your hosting plan. |

**Optional (the defaults are fine)**

| Key | Default | Note |
| --- | --- | --- |
| `TEAM_COST_CAP_PERCENT` | 30 | A paying team's AI cost may use at most this % of its plan price. |
| `AUDIT_DAILY_BUDGET` | 5 | New free audits per day. |
| `CITEVANTA_SCAN_MAX_TOOL_CALLS_FREE` / `_PAID` | 2 / 3 | Web searches per answer. |
| `CITEVANTA_SCAN_MAX_OUTPUT_TOKENS_FREE` / `_PAID` | 1200 / 1600 | Answer length limit. |
| `CITEVANTA_SCAN_REASONING_EFFORT`, `CITEVANTA_SEARCH_CONTEXT_SIZE` | low / low | |
| `APP_ASSET_VERSION` | 1 | Raise to 2, 3, … if a browser still shows the old favicon. |
| `CONTACT_EMAIL`, `BILLING_EMAIL`, `SITE_OG_IMAGE` | empty | Contact form address (empty = admin emails), invoice contact, link-preview image. |
| `BILLING_PROVIDER`, `BILLING_TAX_RATE`, `BILLING_PRICES_INCLUDE_TAX` | manual / 0 / true | |

**Leave empty until you use the feature**

| Key | When |
| --- | --- |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | When you create a Cloudflare Turnstile site (recommended: without it only a hidden field protects the forms). |
| `XENDIT_SECRET_KEY`, `XENDIT_CALLBACK_TOKEN`, `XENDIT_MODE` | Only if you switch `BILLING_PROVIDER` to `xendit`. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_AGENT_EMAIL` | When you set up Google (`docs/GOOGLE-SETUP.md`). **Until then, do not put a team on the Private plan:** with an empty `GOOGLE_CLIENT_ID` the app shows fake sample data. |

---

## Before you take payments (in code, not `.env`)

`config/citevanta.php` still has `CHANGE` placeholders that appear on invoices: the business name and address (`billing.business`), the bank details (`billing.bank`), and the IDR prices (`pricing.prices.*.idr`, empty means the site shows USD only). Fill them in **in the project** and build a new zip; editing them only on the server would be overwritten by the next release.

---

## Upload steps (cPanel File Manager)

1. **Back up.**
   - In File Manager, copy `/home/balj5948/citevanta/.env` to `.env.backup-2026-11-24` (same folder).
   - Export the database: cPanel > phpMyAdmin > your database > Export, or cPanel > Backup.
2. **App zip.**
   1. In File Manager, open the folder `/home/balj5948/citevanta` (you should see `app`, `config`, `vendor`, `.env` inside).
   2. Click **Upload** and choose `citevanta-app-1.6.0-{timestamp}.zip`.
   3. Back in the folder, select the zip, click **Extract**, and make sure **"Overwrite existing files" is on**. Extract to `/home/balj5948/citevanta` (the same folder, not a sub-folder).
3. **Public zip.**
   1. Open the folder `/home/balj5948/public_html/citevanta.a-dashboard.com` (you should see `index.php` and `.htaccess`).
   2. Click **Upload** and choose `citevanta-public-1.6.0-{timestamp}.zip`.
   3. Select the zip, click **Extract**, **"Overwrite existing files" on**, to that same folder.
4. **Add the new `.env` keys** (see above) to `/home/balj5948/citevanta/.env` and save. If `public_html/citevanta.a-dashboard.com` has an old `robots.txt` or `llms.txt`, delete it.
5. **Run Deploy tasks, then Clear caches:** log in, open `https://citevanta.a-dashboard.com/admin/system`, click **Run deploy tasks**, then **Clear caches**. "Nothing to migrate" is normal if the database is already current.
6. **Verify.**
   - Open `/home/balj5948/citevanta/release.json` (File Manager > Edit or View). The `"id"` must be **the release id the build printed** (also written in the build summary you were sent).
   - The admin footer and Admin > System show `v1.6.0 · 24 Nov 2026`, the same release id and the build time.
   - Admin > System says "release.json and the running code agree". A yellow "The upload looks half done" warning means release.json and the running code are different versions: see Troubleshooting.
7. **Check the favicon:** open `https://citevanta.a-dashboard.com/favicon.ico` (a blue magnifier with quote marks). Refresh the tab with Ctrl+F5; if the tab icon is still the old one, set `APP_ASSET_VERSION=2` in `.env` and clear caches.
8. **Quick checks:** `/` (public home page, signed out), `/pricing`, `/llms.txt`, `/robots.txt`, `/site.webmanifest`, and Admin > System > cron shows a recent run.
9. **Clean up:** delete both zips from the server folders.

**If something goes wrong:** restore `.env.backup-2026-11-24` and the database export, and tell me what the page shows.

---

## Troubleshooting

**Nested folder** (you see `/home/balj5948/citevanta/citevanta`, `citevanta/app/`, or a `public_html/.../public` folder): the zip was extracted in the wrong folder or into a sub-folder. These zips have no wrapper folder, so the files must sit directly in the target. Delete the extra folder it created and extract again, with the extract path set to the target folder itself.

**Files not overwritten** (`release.json` still shows the old id, or the footer has no version): "Overwrite existing files" was off, or the extract stopped part-way. Extract the app zip again with **"Overwrite existing files" on**, and check the extract result for errors (a full disk quota also stops it). Then repeat step 6.

**OPcache delay** (the id in `release.json` is new, but the site still behaves like the old version or Admin > System shows the "half done" warning): PHP keeps compiled files for a few minutes. Wait 5 minutes, click **Clear caches** on Admin > System, and reload. If it is still old after 10 minutes, extract the app zip once more.

**Only one zip uploaded**: the app zip alone works (the favicon and new CSS/JS files are missing until the public zip is extracted, so the page may look unstyled or show the old icon). The public zip alone changes nothing in the app. Upload both.
