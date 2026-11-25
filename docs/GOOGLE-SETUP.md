# Google setup (GA4 and Search Console for the Private plan)

The app reads Google Analytics 4 and Search Console through **one Google service account that you own**. Hotels share read access with the service account's email address, the same way they would share it with a person. The account has a **JSON key file** that you upload to the server once; after that the app reads every property and site shared with it. Nobody signs in to Google, so there is no consent screen, no OAuth client and no token that expires.

APIs used (read-only):

| API | Version | Used for |
| --- | --- | --- |
| Google Analytics Data API | v1beta (`properties/{id}:runReport`) | Daily traffic, events, health checks |
| Google Analytics Admin API | v1beta (`accountSummaries`, `properties/{id}`, `dataStreams`, `customDimensions`) | Finding the hotel's property, booking date parameters |
| Search Console API | webmasters/v3 (`sites`, `searchAnalytics/query`) | Daily queries, pages, countries, devices |

Scopes: `https://www.googleapis.com/auth/analytics.readonly` and `https://www.googleapis.com/auth/webmasters.readonly` (read only). Nothing else.

> Search Console's AI Overviews / AI Mode numbers are **not** available through the API (checked 20 Nov 2026), so the app does not show them.

## 1. Create the Google Cloud project

1. Go to <https://console.cloud.google.com/> and sign in with your own Google account.
2. Top bar > project picker > **New project**. Name: the product name (for example "CiteVanta"). Create it and select it.

## 2. Enable the three APIs

**APIs & Services > Library**, search each name, click it and press **Enable**:

1. **Google Analytics Data API**
2. **Google Analytics Admin API**
3. **Google Search Console API**

They must be enabled in the **same project** as the service account, or Google answers "API has not been used in project ... before or it is disabled".

## 3. Create the service account

**IAM & Admin > Service accounts > Create service account**:

- Name: for example `citevanta-reader`. The email Google creates looks like `citevanta-reader@your-project.iam.gserviceaccount.com`. **Hotels see this address** in their GA4 user list, so choose a clear name.
- You do **not** need to grant it any role in the project. Skip "Grant this service account access to project" and "Grant users access".

## 4. Create the JSON key

Open the service account > **Keys > Add key > Create new key > JSON > Create**. Your browser downloads a `.json` file **once**; Google cannot show it again.

Treat this file like a password:

- It is the only thing that proves the app is this service account.
- Never paste it into chat, email, `.env`, a ticket or git, and never put it in `public_html`.
- Keep one copy in a password manager (or note that you can always create a new key and delete the old one).

If Google refuses to create keys ("Service account key creation is disabled"), your organisation has the policy `iam.disableServiceAccountKeyCreation` turned on. An organisation admin can allow it for this project.

## 5. Upload the key to the server

On cPanel (File Manager), in the app folder, for example `/home/balj5948/citevanta`:

1. Open `storage/app/private/`, create a folder `google` (if it does not exist).
2. Upload the key into it and rename it `citevanta-google.json`:
   `/home/balj5948/citevanta/storage/app/private/google/citevanta-google.json`
3. Right-click the file > **Change Permissions** > set **640** (owner read and write, group read, others nothing). Do the same for the folder with **750**.
4. Delete the downloaded copy from your Downloads folder.

This folder is outside the web root (`public_html/...`), so no one can open the file through the browser. `storage/app/private/google/` is in `.gitignore`, and the release zip never includes key files: `citevanta:package` skips any `service-account*.json`, `credentials*.json`, `google-key*.json` and `*-google.json`, and refuses to package an `.env` template that contains a private key.

## 6. Set the path in `.env`

On the server (`/home/balj5948/citevanta/.env`):

```
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/home/balj5948/citevanta/storage/app/private/google/citevanta-google.json
```

For local development you may use a path relative to the `storage` folder, for example `app/private/google/citevanta-google.json`.

**Empty path = fake Google data** (local development and tests): the app shows sample numbers and a sample address and never calls Google. After changing `.env` on the server: Admin > System > **Clear caches**.

The old OAuth settings `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_AGENT_EMAIL` are no longer used: remove them from `.env`. Hotels that were already linked keep their saved GA4 property and Search Console site.

## 7. Test the connection

Log in as an admin: **Admin > Integrations > Google**. The page shows:

- the **service account email** (read from the key file) with a **Copy email** button: this is what you give to hotels,
- whether the **key file is found and valid** (never its contents),
- the **last successful call**,
- a **Test connection** button, which asks Google for the GA4 accounts shared with the service account. A new account has none yet; "Google accepted the key" is the success message.

## 8. What a hotel does

On **Settings > Connect Google** (Private plan owners) the hotel sees the service account email with a copy button and these steps:

- **GA4**: Admin > Property access management > + > Add users > paste the email > role **Viewer** > untick "Notify new users by email" > Add.
- **Search Console**: Settings > Users and permissions > Add user > paste the email > Permission **Restricted** > Add.

Then **Check connection** lists only the GA4 properties and Search Console sites this service account can see, filtered by the hotel's website domain. If nothing is shared yet, the page says so. The owner picks the property and site; the tracking health check and the first sync start (GA4: last 25 months, so a year can be compared with the year before; Search Console: last 16 months, its limit, one job per day; then daily).

## If Google refuses the key

When Google rejects the key (deleted, disabled, or a different project), the app:

- marks the connection broken, shows a **red banner** to admins, and pauses the GA4 and Search Console syncs,
- **emails the admins once**.

Check that the service account and its key still exist in Google Cloud (IAM & Admin > Service accounts > Keys) and that the file on the server is the current key. Then open Admin > Integrations > Google and click **Test connection**: a successful test clears the banner and the syncs continue.

## Replacing the key (rotation)

1. In Google Cloud, create a **new** JSON key for the same service account (step 4).
2. Upload it over `citevanta-google.json` (step 5) with permission 640.
3. Admin > Integrations > Google > **Test connection**.
4. Only then delete the old key in Google Cloud.

Hotels do not need to do anything: the service account email stays the same.

## How much history is read, and older data for teams already synced

The first GA4 sync copies `GA4_FIRST_SYNC_MONTHS` months (default **25**) in ordered windows of 31 days, oldest first. Search Console stays at 16 months, which is the most it keeps.

A team that was synced with fewer months (for example 13) is not copied again. Use **Backfill older data** (Admin > Integrations > Google, pick the team and hotel) or `php artisan citevanta:google-backfill {team}`: it reads only the days older than the oldest stored day, up to the configured number of months, with the same ordered, windowed method. Each window replaces only its own dates (replace by date), so nothing newer is touched and the reports never go empty. Run it once after upgrading; the daily gap check then keeps the days complete.

## "All sessions" come from daily totals

Adding up the detailed traffic rows (source, medium, country, landing page, device) can give **more** sessions than GA4 reports for the same days, because GA4 is not additive across those dimensions. So the sync also copies **daily totals** (a GA4 request with only the date dimension: sessions, engaged sessions, key events, purchases, revenue) into `ga4_totals_daily`. "All sessions", "all purchases", "all revenue", the AI share percentages and the findings use the daily totals. AI sessions, purchases and revenue still come from the detailed rows, which match GA4. A day that has no daily total yet (right after an upgrade) uses its detailed rows, so a report is never empty; **Sync now** or the next daily sync fills the totals in.

The **self-check** after each sync compares the stored all-traffic totals and the stored AI rows with live GA4 for the last 28 days and warns when either differs by more than 2%. Admins see the numbers; hotel users see "Some totals are being checked and may change."

**Run diagnostics** (Admin > Integrations > Google) shows, for the last 28 days, the stored rows by sync batch and whether any date has rows of more than one batch, and three live GA4 totals for the same range: the detailed rows added up, only the date dimension added up and no dimensions, so you can see whether GA4 is non-additive for a property.

## Sync now (last 7 days)

**Sync now (last 7 days)** on Admin > Integrations > Google, or `php artisan citevanta:google-sync-now {team} [--days=7]`, reads only the last 7 days of traffic, daily totals, events and booking dates and replaces them by date. The history is not touched. Use it after a fix, or to refresh the latest days.

## The Reports page (Private plan)

- **Date range:** presets (last 7, 28, 30 and 90 days, this month, last month, last 12 months, this year) or a custom range on a two-month calendar. The range starts no earlier than the first synced day; a custom range ends no later than the last fully synced day. The selection is in the URL (`?from=&to=&compare=`), so it can be bookmarked or shared, and it applies to both tabs.
- **Compare:** no comparison, the previous period (same length, directly before; the default) or the same period last year (optionally shifted 52 weeks so the weekdays match, `&dow=1`). A comparison that would start before the first synced day is disabled and the page says why ("data starts ..."). Days that were never synced are blank, never zero. The comparison line is dashed in the charts, the cards show the % change and the tables get previous and change columns.
- **Tables:** 10, 50 or 100 rows per page (10 by default, remembered per user), sortable columns (the sort covers all rows) and page controls. The page, sort and size are in the URL (`?size=50&page[ai-pages]=2&sort[ai-pages]=sessions:desc`). The CSV button always exports every row of the selected range in the chosen order, with the comparison columns when a comparison is on; the range is in the file name.

## Searches by date: the guest's country

The booking-dates sync (the table `ga4_booking_dates_daily`, one row per date, event, check-in, stay and **country**) also reads GA4's **country** dimension. A request has 5 dimensions at most (date, event, check-in, the stay field, country), well within GA4's limit of 9, and the sum over the countries of a day is the same total as before.

- The country is stored **as GA4 returns it** (a name only, no city or region, nothing about the person). An empty country and GA4's "(not set)" are shown as **Unknown**.
- The country is part of each row's unique key (`row_key`), so the upsert and the replace-by-window logic keep working. Rows synced before this change have an empty country and keep their old key; they are replaced by date the next time their window is read (a re-sync, **Sync now** or the daily sync), so they are never counted twice once their window is done.
- **Re-sync from scratch** fills the country for every day it copies, and so do **Sync now** and the daily sync (the last 3 days). The data only exists since 24 Nov 2026, so the re-sync is small.
- In **Reports > AI traffic and bookings > Searches by date**:
  - The **calendar tooltip** shows "Top countries" under the three counts: up to 3 lines like "Australia 2", and "+2 more" when there are more. The lines count **searches only**, so together with the "+N more" countries they add up to the "searched" number above them (started bookings and bookings are not counted per country).
  - A **Country** dropdown ("All countries" by default) filters the calendar, the stay-length chart and table, and the CSV. It follows the date picker and the comparison like the rest of the report.
  - The **CSV** button of the dates table downloads one row per check-in date, stay length and country (with the previous-period columns when a comparison is on). The per-date file is still available as `demand-checkin`.

## Quotas

The sync makes one API call per job and at most 30 calls a minute (`GOOGLE_REQUESTS_PER_MINUTE`). GA4 standard properties allow 40,000 core tokens per hour and 200,000 per day per property; a typical report costs about 10 tokens. Search Console allows 1,200 queries per minute per site. On a quota error the job waits 15 minutes and retries. The access token is requested once and kept for about 50 minutes.
