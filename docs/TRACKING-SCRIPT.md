# Website tracking script (Private plan)

A small JavaScript snippet that the hotel's web developer adds to the hotel's own website. It counts two things the booking engine and GA4 alone do not tell us:

- **AI referral visits:** a visitor arrives from an AI assistant (the referrer or `utm_source` matches the AI list in `config/citevanta.php` > `ai_sources`).
- **Booking clicks with dates:** a visitor clicks a booking link or button; the check-in date, check-out date and guests are read from the link's query string (or from date fields on the page).

It is meant to sit next to the GA4 reports: "Seen by the website script" appears in **Reports > AI traffic and bookings**.

## What it does and does not do

| It does | It never |
| --- | --- |
| Loads async, in the background, in one small file (no libraries) | Sets a cookie, or reads or writes `document.cookie` |
| Sends a beacon with the **platform**, the **page path** and, for a booking click, the **stay dates and guests** | Uses local storage, session storage or IndexedDB |
| Pushes a GA4 event `ai_referral` (parameter `ai_platform`) and `booking_click` (parameters `check_in_date`, `check_out_date`, `nights`, `adults`, `children`) when `gtag` or `dataLayer` exists | Fingerprints the browser (no canvas, fonts, screen or user agent) |
| Catches every error inside the script | Sends the full URL, the query string, an e-mail address or any text a visitor typed |
| Stops sending when the hotel turns it off | Stores or logs an IP address (see "No IP addresses") |

## Add it to the website

1. Sign in as the team owner (Private plan) and open **Settings > Website script**.
2. Copy the line `<script async src="https://.../t/cvk_....js"></script>` and give it to the web developer. It goes before the closing `</head>` tag, on every page.
3. Click **Check installation**: CiteVanta opens the hotel's home page and looks for the line. (A site that adds scripts after the page loads may still work: use the debug mode.)

The public key (`cvk_...`) is in the page source of the website. It is not a secret: it only lets the script report for that hotel **from the hotel's own website**.

### What the script reads (Settings > Website script)

- **Booking links and buttons:** CSS selectors, one per line (default: `[data-booking]`, `[data-citevanta-booking]`, `a.book-now`, `a.booking-link`, `button.book-now`). Every link to the hotel's booking engine domain (Connect Google > Booking engine) is always included.
- **Parameter names in the booking link** for check-in, check-out, adults and children (comma separated). The defaults cover `checkin`, `check_in_date`, `arrival`, `adults`, `children`, and similar.
- **Fields on the page** (CSS selectors) that are read when the link has no dates.
- **Date format:** dates written `2026-12-31` are always understood; `31/12/2026` is read day first or month first as chosen.

Changes apply to the script by themselves within 5 minutes (the script file is cached for 5 minutes). Nothing needs to change on the website.

### Debug mode

Open any page of the website with `?citevanta_debug=1` at the end of the address. A small box in the corner lists what the script detects (AI visits, and booking clicks with the dates it read). Nothing extra is sent.

### Privacy policy sentence

Settings > Website script shows a ready sentence with a copy button. It says the script sets no cookies, stores nothing in the browser, does not use the IP address and cannot identify a visitor.

## The endpoint

`POST /t/collect` (no session, no cookies, no CSRF; routes in `routes/tracking.php`).

- **Public key:** the hotel is found by its key. An unknown key, a switched-off hotel or a team that is no longer on the Private plan gets 404.
- **CORS:** only the hotel's own website is allowed: the `Origin` must be the website's domain or a subdomain of it (not its parent domain). Any other origin gets 403 and no CORS headers. Preflight requests are not allowed; the script only sends simple requests (`text/plain`).
- **Rate limit:** `TRACKING_RATE_PER_MINUTE` (default 300) per public key, never per IP address.
- **Validation:** the body is at most 2 KB of JSON. The platform must be one of the known AI platforms. The path is the path only (query string and fragment are cut off). Dates must be real, the check-in from yesterday to two years ahead, the check-out one to 90 nights after it; guests 1 to 20 adults and 0 to 20 children. Anything else is dropped (422); it is never repaired into something else.
- **The browser never sees an error page:** the endpoint answers with a status code and an empty body, and the script ignores the answer.

## No IP addresses

The controller, the collector and the script generator never read the IP address, the user agent or any header besides `Origin`. The rate limiter uses the public key. The tables `tracking_events` and `hotel_tracking` have no column for an address, a user agent, a session or a person; a test checks the code, the columns, the database rows and the cache.

## Data kept

`tracking_events` holds: type, platform, page path, check-in, check-out, nights, adults, children, time. Events are kept `TRACKING_RETENTION_MONTHS` months (13) and pruned by the daily housekeeping job.

## Check installation (SSRF safety)

The check fetches the hotel's home page from the server, so it never connects to our own network: http and https only, no user name, ports 80 and 443 only, and the host must resolve only to public addresses (no private, loopback, link-local, carrier-grade NAT or reserved range). The connection is pinned to the checked address, redirects are checked again at every hop (at most 3), the page is cut at 1.5 MB and the request stops after 8 seconds.

## Settings (`.env`)

| Key | Default | Meaning |
| --- | --- | --- |
| `TRACKING_RATE_PER_MINUTE` | 300 | Events accepted per minute for one public key |
| `TRACKING_RETENTION_MONTHS` | 13 | Months the events are kept |
