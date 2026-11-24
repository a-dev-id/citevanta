# Release 1.8.0 (24 Nov 2026)

Two zips, in `releases/`. **Each one is uploaded into its own target folder and extracted there**, so nothing can land in the wrong place:

| Zip | Open this folder in File Manager | What is inside (at the zip root, no wrapper folder) |
| --- | --- | --- |
| `citevanta-app-1.8.0-{timestamp}.zip` | `/home/balj5948/citevanta` | the whole app, `vendor/` without dev packages, `public/build/`, `release.json` and this file |
| `citevanta-public-1.8.0-{timestamp}.zip` | `/home/balj5948/public_html/citevanta.a-dashboard.com` | `build/`, `images/` and the favicon files |

Not in the zips, so the live ones stay as they are: `.env`, everything in `storage/` (so a Google key file you upload there is never overwritten), and in the document root the `index.php` (your `usePublicPath` edit) and `.htaccess` (unchanged since 18 Nov).

`robots.txt`, `llms.txt`, `sitemap.xml` and `site.webmanifest` are **not files**: the app generates them. A static `robots.txt` or `llms.txt` in `public_html` would hide the app's version: delete one if it exists.

Every build writes a new `release.json` (`id`, `version`, `build_date`, `built_at`) into the app zip, and the build prints the same **release id**. That id is how you prove the upload worked (step 6).

---

## What changed in 1.8.0 (on top of 1.7.1)

- **Reports:** date picker with presets and a custom two-month calendar (kept in the URL, both tabs); comparison with the previous period (default) or the same period last year (optionally matching the day of week), disabled with a reason when the data does not reach back far enough, never showing missing days as zero; dashed comparison lines, % change on the cards, previous and change columns; every table paged on the server (10, 50, 100 rows, remembered per user), sortable over all rows, with the page, sort and size in the URL; CSV always has every row of the range (and the comparison columns).
- **GA4 history is 25 months** (`GA4_FIRST_SYNC_MONTHS`, default 25). **Backfill older data** (Admin > Integrations > Google, or `php artisan citevanta:google-backfill {team}`) reads only the older windows for teams already synced.
- Findings no longer name "(not set)" as a page.
- **Migrations:** 2 new (see below).
- **New `.env` key (optional):** `GA4_FIRST_SYNC_MONTHS=25`.
- **Sync and data quality:** sync jobs that no longer run out of attempts (no more `MaxAttemptsExceeded`), are unique per step and retry safely after a kill; a daily gap check (`citevanta:google-heal`) that queues missing days; a self-check after each sync that warns "data may be incomplete" when stored sessions or purchases differ from live GA4 by more than 2%; and **Searches by date** (the stay dates now come from every event that carries the check-in date, including `search_dates`).

---

## What changed in 1.7.1 (on top of 1.7.0)

**Google data now arrives complete and in order** (fixes AI sessions and purchases that looked far too low next to Looker Studio)
- GA4 rows are requested in a fixed order (oldest date first) and in windows of 31 days, one window after the other. Before, one request covered 13 months with no order, so pages could be missed and GA4 could fold rows into "(other)". If GA4 still reports "(other)" rows, a warning is written to the log (no secrets in it).
- Old rows are kept until a window is fully copied, then replaced by date. A re-sync or a failed run never leaves the reports emptier than before.

**Reports show what is really there**
- Days that have not been copied yet are blank in the AI charts, not zero, and a notice says **"Data complete through <date>"** while the copy runs.

**Admin > Integrations > Google: Diagnostics and re-sync**
- **Run diagnostics** (pick the team and hotel, and a day; the day defaults to 10 days ago): stored date range and days with rows, waiting and failed Google jobs and whether the first sync is still running, the exact GA4 request, stored against live GA4 for that day and for the last 28 days, the "(other)" check, and the purchase numbers side by side: `ecommercePurchases`, `transactions` and the `purchase` event count (so we can decide which one is a "purchase" with live numbers; the metric is **not** changed yet). Read-only. The output never contains a token or key content.
- **Re-sync from scratch** (asks for a confirmation): copies the 13 months of GA4 data again for every linked hotel of the chosen team, with the new ordered, windowed method. Search Console is not touched.
- The same from the command line: `php artisan citevanta:google-diagnose "Nandini" --date=2026-11-10` and `php artisan citevanta:google-resync "Nandini"`.

---
## What changed in 1.7.0 (since 1.6.0)

**Reports for Private teams** (menu item "Reports", visible only on the Private plan)
- **AI traffic and bookings (GA4):** sessions, purchases and revenue from AI assistants, their share of all sessions and purchases, by AI platform, country and landing page, with charts and a CSV button on every table. The same period last year is shown once 13 months of data exist.
- **Demand by date:** a heat-map calendar of the check-in dates guests looked at or started booking (hold the mouse over a night for the stays behind it), a length-of-stay chart and a countries panel. It needs the booking date fields mapped in Settings > Connect Google; otherwise it shows the setup hint and the message for the web developer.
- **Google Search (Search Console):** top queries, pages and countries, seasonal query groups (Christmas, New Year, Nyepi and others) and low-click-rate opportunities. AI Overviews numbers are not available through Google's API, and the page says so.
- Date range picker (last 28 days, 90 days, 12 months), plain-language findings that link to Recommendations, and empty states that explain what is missing (not connected, tracking check red, data still syncing). Reports read only our own synced tables; they make no Google calls.
- The daily Google sync now also copies the booking dates (check-in, check-out, nights) when they are mapped.

**Google connects with a service account instead of an OAuth login**
- The app signs in to Google with a key file (JSON) that you upload to the server once. No consent screen, no sign-in, no token that expires after 7 days.
- Hotels share GA4 (**Viewer**) and Search Console (**Restricted**) with the service account's email, shown with a copy button in Settings > Connect Google and on Admin > Integrations > Google.
- Admin > Integrations > Google shows the email, whether the key file is found and valid (never its contents), the last successful call and a **Test connection** button. If Google refuses the key, a red banner shows and the admins get one email; Test connection clears it once the key works again.
- The key is never saved in the database, shown, logged or put in an error message, and the release tool never packs key files.
- Hotels that were already linked keep their saved GA4 property and Search Console site. The old OAuth sign-in and its settings are gone.

**Release tools**
- `citevanta:package` skips any Google key file and refuses an `.env` template that contains a private key.

---

## New migrations (run by "Run deploy tasks")

If you are coming from 1.7.1, only numbers 3 and 4 are new. From 1.7.0, 2 to 4. From 1.6.0, all four.

1. `2026_11_24_091221_add_booking_dates_for_reports` (1.7.0): one table (synced booking dates) and one column on `hotel_google_links`.
2. `2026_11_24_132838_add_sync_batch_to_ga4_tables` (1.7.1): one small empty column (`sync_batch`) on the three GA4 tables.
3. `2026_11_24_135108_add_data_check_to_hotel_google_links`: one empty column for the self-check result.
4. `2026_11_24_140130_add_report_page_size_to_users`: one empty column for the rows-per-page choice.

Nothing is deleted by any of them.

---

## `.env` changes

All are in `.env.production.example`. Copy the new lines into the live `.env`.

**Only if you turn Google on now** (otherwise leave it out: an empty path means fake sample data)

| Key | What to do |
| --- | --- |
| `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` | Full path of the key file on the server, for example `/home/balj5948/citevanta/storage/app/private/google/citevanta-google.json`. See "Turn Google on" below. **Until it is set, do not put a team on the Private plan:** the app shows fake sample data. |

**Remove if present (no longer used):** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_AGENT_EMAIL`.

**Optional (the defaults are fine)**

| Key | Default | Note |
| --- | --- | --- |
| `GA4_FIRST_SYNC_MONTHS` | 25 | Months of GA4 history the first sync copies. New in 1.8.0. |

(Other optional keys:)

| Key | Default | Note |
| --- | --- | --- |
| `GOOGLE_REQUESTS_PER_MINUTE` | 30 | Google calls per minute from the sync jobs. |
| `REPORT_OPPORTUNITY_MIN_IMPRESSIONS` | 100 | A query needs this many impressions to count as a click-rate opportunity. |
| `LOG_DAILY_DAYS` | 14 | Daily log files kept. |
| `APP_PREVIOUS_KEYS` | empty | Only after you ever replace `APP_KEY`. |
| `CITEVANTA_ACCURACY_CHECKS`, `_COMPETITOR_REASONS`, `_RECOMMENDATIONS`, `_RECOMMENDATION_WRITING`, `_CONTENT` | true | Switch an AI feature off to save cost. |
| `CITEVANTA_ANALYSIS_MAX_OUTPUT_TOKENS`, `CITEVANTA_RATE_LIMIT_OPENAI`, `CITEVANTA_WEB_SEARCH_TOOL`, `CITEVANTA_WEB_SEARCH_TOOL_CHOICE` | 800 / 30 / web_search / auto | Advanced limits; leave as they are. |

Still needed from 1.6.0 (you should already have it): `CITEVANTA_ANALYSIS_REASONING_EFFORT=none`.

---

## Turn Google on (when you are ready for the first Private hotel)

The full guide is `docs/GOOGLE-SETUP.md` (in the zip, in the project root). In short:

1. In Google Cloud, create a project, enable **Google Analytics Data API**, **Google Analytics Admin API** and **Google Search Console API**, create a service account and a **JSON key**.
2. In File Manager open `/home/balj5948/citevanta/storage/app/private/`, create a folder `google`, upload the key as `citevanta-google.json`, and set its permission to **640** (folder **750**). This folder is outside `public_html`.
3. Add `GOOGLE_SERVICE_ACCOUNT_JSON_PATH=...` to `.env` (table above), then Admin > System > **Clear caches**.
4. Admin > Integrations > Google: the email and "Found and valid" must show; click **Test connection**.
5. Give the service account email to the hotel (it is also on Settings > Connect Google).

Never put the key file in `public_html`, an email, chat or git.

---

## Before you take payments (in code, not `.env`)

`config/citevanta.php` still has `CHANGE` placeholders that appear on invoices: the business name and address (`billing.business`), the bank details (`billing.bank`), and the IDR prices (`pricing.prices.*.idr`, empty means the site shows USD only). Fill them in **in the project** and build a new zip; editing them only on the server would be overwritten by the next release.

---

## Upload steps (cPanel File Manager)

1. **Back up.**
   - In File Manager, copy `/home/balj5948/citevanta/.env` to `.env.backup-2026-11-24-1.8.0` (same folder).
   - Export the database: cPanel > phpMyAdmin > your database > Export, or cPanel > Backup.
2. **App zip.**
   1. In File Manager, open the folder `/home/balj5948/citevanta` (you should see `app`, `config`, `vendor`, `.env` inside).
   2. Click **Upload** and choose `citevanta-app-1.8.0-{timestamp}.zip`.
   3. Back in the folder, select the zip, click **Extract**, and make sure **"Overwrite existing files" is on**. Extract to `/home/balj5948/citevanta` (the same folder, not a sub-folder).
3. **Public zip.**
   1. Open the folder `/home/balj5948/public_html/citevanta.a-dashboard.com` (you should see `index.php` and `.htaccess`).
   2. Click **Upload** and choose `citevanta-public-1.8.0-{timestamp}.zip`.
   3. Select the zip, click **Extract**, **"Overwrite existing files" on**, to that same folder.
4. **`.env`:** remove the old `GOOGLE_*` lines if present, and add the optional lines you want (see above). If you are not turning Google on yet, change nothing else.
5. **Run Deploy tasks, then Clear caches:** log in, open `https://citevanta.a-dashboard.com/admin/system`, click **Run deploy tasks** (it runs the new migrations), then **Clear caches**.
6. **Verify.**
   - Open `/home/balj5948/citevanta/release.json`. The `"id"` must be **the release id the build printed**.
   - The admin footer and Admin > System show `v1.8.0 · 24 Nov 2026`, the same release id and the build time.
   - Admin > System says "release.json and the running code agree". A yellow "The upload looks half done" warning means release.json and the running code are different versions: see Troubleshooting.
7. **Quick checks:** `/` (public home page, signed out), `/pricing`, Admin > Integrations > Google (opens, shows the email or "Not set (fake data)"), and, as a Private team, the **Reports** menu item. Admin > System > cron shows a recent run.
8. **Clean up:** delete both zips from the server folders.
9. **Copy the older GA4 history once (hotels already linked):** in Admin > Integrations > Google pick the team and hotel and click **Run diagnostics** first (note the numbers). If this is the first upload since 1.7.0, click **Re-sync from scratch** (ordered, windowed copy of the whole history); otherwise click **Backfill older data**, which reads only the days older than the oldest stored day, back to 25 months. The reports keep showing the old data while it fills in; "Data complete through ..." shows the progress. When no Google jobs are waiting, run the diagnostics again and send me the output. Search Console is not touched.

**If something goes wrong:** restore `.env.backup-2026-11-24-1.8.0` and the database export, and tell me what the page shows.

---

## Troubleshooting

**Nested folder** (you see `/home/balj5948/citevanta/citevanta`, `citevanta/app/`, or a `public_html/.../public` folder): the zip was extracted in the wrong folder or into a sub-folder. These zips have no wrapper folder, so the files must sit directly in the target. Delete the extra folder it created and extract again, with the extract path set to the target folder itself.

**Files not overwritten** (`release.json` still shows the old id, or the footer still shows the old version): "Overwrite existing files" was off, or the extract stopped part-way. Extract the app zip again with **"Overwrite existing files" on**, and check the extract result for errors (a full disk quota also stops it). Then repeat step 6.

**OPcache delay** (the id in `release.json` is new, but the site still behaves like the old version or Admin > System shows the "half done" warning): PHP keeps compiled files for a few minutes. Wait 5 minutes, click **Clear caches** on Admin > System, and reload. If it is still old after 10 minutes, extract the app zip once more.

**Only one zip uploaded**: the app zip alone works, but the new CSS and JavaScript are missing until the public zip is extracted, so the Reports calendar and other pages may look unstyled. The public zip alone changes nothing in the app. Upload both.

**Google test fails**: the page shows the reason. "Key file not found" means the path or folder is wrong; "not valid" means the file is not the service account JSON; "refused" means the key was deleted or disabled in Google Cloud; "API has not been used" means one of the three APIs is not enabled in the same Google Cloud project.
