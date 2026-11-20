# Deploying CiteVanta to cPanel shared hosting

This guide takes you from your computer to a working CiteVanta on a cPanel
host, step by step. It works **with or without SSH**.

Throughout the guide:

- `USER` is your cPanel username (shown top right in cPanel).
- `DOMAIN` is your domain, for example `example.com`.
- The app lives in **`/home/USER/citevanta`** (outside `public_html`, so
  nobody can download your files).
- The app is served at **`https://app.DOMAIN`**.

> Host facts this guide assumes (tested 18 Nov 2026): LiteSpeed, CloudLinux,
> PHP 8.4, `proc_open`/`exec` disabled, web requests limited to 30 seconds
> (CiteVanta raises this itself), cron every minute with `/usr/local/bin/php`.

---

## Contents

1. [Before you start](#1-before-you-start)
2. [Prepare the release on your computer](#2-prepare-the-release-on-your-computer)
3. [Create the database](#3-create-the-database)
4. [Upload the app](#4-upload-the-app)
5. [Point the website at the app](#5-point-the-website-at-the-app)
6. [Create the .env file](#6-create-the-env-file)
7. [Set up email](#7-set-up-email)
8. [First-time setup: database tables and the admin](#8-first-time-setup-database-tables-and-the-admin)
9. [Add the cron job](#9-add-the-cron-job)
10. [Log in and check Admin > System](#10-log-in-and-check-admin--system)
11. [First real scan checklist](#11-first-real-scan-checklist)
12. [Updating the app later](#12-updating-the-app-later)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Before you start

You need:

- **cPanel access** for the hosting account.
- **An OpenAI API key** with billing enabled: <https://platform.openai.com/api-keys>.
- **PHP 8.4** for the domain. In cPanel open **MultiPHP Manager** (or
  **Select PHP Version**) and choose PHP 8.4 for `app.DOMAIN`.
  Make sure these extensions are on (they usually are): `pdo_mysql`,
  `mbstring`, `openssl`, `curl`, `fileinfo`, `intl`, `xml`, `zip`, `bcmath`.
- **On your computer**: the project, PHP 8.3+, Composer and Node.js (the same
  tools you use for local development).

**Do you have SSH?** In cPanel, look for **Terminal** (under *Advanced*) or
**SSH Access**. If you can open a terminal on the server, follow the
**"With SSH"** parts below. If not, follow **"Without SSH"**: everything can be
done from cPanel and the CiteVanta admin pages.

---

## 2. Prepare the release on your computer

### Without SSH: build a release zip

In the project folder on your computer:

```bash
php artisan citevanta:package
```

This builds the assets (`npm run build`), copies the project to a temporary
folder **without** `.env`, tests, `node_modules` and logs, installs the
production packages (`composer install --no-dev --optimize-autoloader`) and
zips it. It prints the zip path and size, for example:

```
Release ready:
  D:\...\citevanta2\releases\citevanta-2026-11-20-101500.zip
  13.9 MB
```

The zip contains `vendor/` and `public/build/`, so the server needs neither
Composer nor Node.

### With SSH: use git

1. Put the project in a **private** git repository (GitHub, GitLab, ...).
2. Shared hosting has no Node.js, so **commit the built assets**: run
   `npm run build` and commit `public/build/` before every deploy
   (`public/build` is deliberately not in `.gitignore`).
3. Never commit `.env`.

---

## 3. Create the database

1. cPanel > **MySQL Databases**.
2. **Create New Database**: `citevanta`. cPanel names it `USER_citevanta`.
3. **Add New User**: `citevanta` with a strong password (use the generator).
   It becomes `USER_citevanta`. Save the password; you need it in step 6.
4. **Add User To Database**: choose the user and the database, tick
   **ALL PRIVILEGES**, save.

CiteVanta creates its tables with the **InnoDB** engine (needed for foreign
keys and transactions; `DB_ENGINE=InnoDB` in `.env`).

---

## 4. Upload the app

### Without SSH: upload the zip

1. cPanel > **File Manager**. Go to your home folder (`/home/USER`).
2. Click **+ Folder**, name it `citevanta`, open it.
3. Click **Upload** and upload the release zip.
4. Back in File Manager, select the zip, click **Extract**, extract into
   `/home/USER/citevanta`. You should now see `app`, `public`, `vendor`,
   `artisan` and so on directly inside `citevanta`.
5. Delete the zip.

### With SSH

```bash
cd ~
git clone git@github.com:YOUR-ACCOUNT/citevanta.git citevanta
cd citevanta
composer install --no-dev --optimize-autoloader
```

If `composer` is not found, use the copy many hosts provide
(`/opt/cpanel/composer/bin/composer`), or download it into your home folder:
`curl -sS https://getcomposer.org/installer | php` and use `php ~/composer.phar`.

### Folder permissions (both ways)

These folders must be writable by PHP: `storage` and everything in it, and
`bootstrap/cache`. In File Manager select them, **Permissions**, and use
**755** for folders (cPanel/CloudLinux runs PHP as your own user, so 755 is
enough; never use 777).

---

## 5. Point the website at the app

### Option A (recommended): a subdomain whose document root is `public/`

1. cPanel > **Domains** (or **Subdomains**) > **Create A New Domain**.
2. Domain: `app.DOMAIN`.
3. **Untick** "Share document root" and set the **Document Root** to:
   `/home/USER/citevanta/public`
4. Submit.
5. cPanel > **SSL/TLS Status** > select `app.DOMAIN` > **Run AutoSSL**, so
   HTTPS works (CiteVanta redirects all traffic to HTTPS in production).

Only the `public` folder is reachable from the web. `.env`, `vendor`,
`storage` and your code are outside the web root. This is the safe setup.

### Option B (fallback): the document root cannot be changed

Some hosts only let a domain point at `public_html` (or a folder you cannot
choose). Then:

1. Put the app in that folder (for example extract the zip into
   `/home/USER/public_html/app`), so `public_html/app/public/index.php` exists.
2. Copy `docs/root.htaccess.example` to the app's root folder and rename it
   to `.htaccess` (next to `artisan`, not inside `public`).

That `.htaccess` sends every request into `public/`, refuses `.env`, other
dot-files and private folders, and turns off directory listings.

**Security trade-off:** with option B all your files are inside the web root
and only the `.htaccess` rules keep them private. If those rules ever stop
working (a host configuration change, mod_rewrite turned off, the file
deleted or overwritten during an update), files such as `.env` (database
password, OpenAI key, APP_KEY) could be downloaded. Use option A whenever
possible. With option B, after every update open
`https://app.DOMAIN/.env` and `https://app.DOMAIN/composer.json` in your
browser: both must show **403 Forbidden**.

---

## 6. Create the .env file

`.env` holds your settings and secrets. It is never in the zip or in git.

1. In File Manager open `/home/USER/citevanta`, select
   `.env.production.example`, **Copy** it to `.env`
   (turn on **Settings > Show Hidden Files** to see dot-files).
2. **Edit** `.env` and fill in every value marked `CHANGE`:
   - `APP_URL=https://app.DOMAIN`
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from step 3.
   - `OPENAI_API_KEY` (starts with `sk-`).
   - `MAIL_*` (step 7).
   - `CRON_TOKEN`: a random secret of at least 40 characters. On your
     computer run `php -r "echo bin2hex(random_bytes(24));"` and paste the
     result (48 characters).
   - `APP_KEY`: on your computer, in the project folder, run
     `php artisan key:generate --show` and paste the whole output
     (it starts with `base64:`). With SSH you can instead run
     `php artisan key:generate --force` on the server.
3. Keep `APP_ENV=production` and `APP_DEBUG=false`.
4. Keep `CITEVANTA_ENGINE=openai`, `CITEVANTA_SCAN_MODEL=gpt-6.1-terra`,
   `CITEVANTA_ANALYSIS_MODEL=gpt-6-luna`.

> Keep a copy of your `.env` somewhere safe (a password manager). If you lose
> `APP_KEY`, everyone has to log in again.

---

## 7. Set up email

CiteVanta sends email for **verify your email address** and **reset password**.

1. cPanel > **Email Accounts** > **Create**: `noreply@DOMAIN` with a strong
   password.
2. Click **Connect Devices** next to it. Under *Secure SSL/TLS Settings* note
   the **Outgoing Server** (usually `mail.DOMAIN`) and the **SMTP Port**
   (usually 465).
3. In `.env`:

   ```
   MAIL_MAILER=smtp
   MAIL_SCHEME=smtps
   MAIL_HOST=mail.DOMAIN
   MAIL_PORT=465
   MAIL_USERNAME=noreply@DOMAIN
   MAIL_PASSWORD=the-email-password
   MAIL_FROM_ADDRESS=noreply@DOMAIN
   ```

   If your host shows port 587 instead, use `MAIL_SCHEME=smtp` and `MAIL_PORT=587`.

---

## 8. First-time setup: database tables and the admin

Two things must happen once: create the database tables and create your admin
login. **Do not run the demo seeder in production**: it creates accounts with
the password `password` (CiteVanta blocks it in production).

### With SSH

```bash
cd ~/citevanta
php artisan migrate --force
php artisan citevanta:make-admin you@DOMAIN
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

`citevanta:make-admin` asks for a password (at least 12 characters; press
Enter to generate one). If the email already has an account, it is made an
admin instead.

### Without SSH: one-off cron commands

cPanel's cron can run any command once for you. You add a line, wait a
minute, read the result in a log file, then delete the line.

1. cPanel > **Cron Jobs**. Under *Add New Cron Job* choose
   **Once Per Minute (\* \* \* \* \*)** and enter this command:

   ```
   cd /home/USER/citevanta && /usr/local/bin/php artisan migrate --force >> storage/logs/setup.log 2>&1
   ```

2. Wait **two minutes**. In File Manager open
   `citevanta/storage/logs/setup.log`. You should see the migrations listed
   with **DONE**. (The second run says "Nothing to migrate", which is fine.)
3. **Delete** that cron line (cPanel > Cron Jobs > Delete).
4. Add another once-per-minute line to create your admin:

   ```
   cd /home/USER/citevanta && /usr/local/bin/php artisan citevanta:make-admin you@DOMAIN --no-interaction >> storage/logs/setup.log 2>&1
   ```

5. Wait a minute, open `setup.log`: it shows `Admin you@DOMAIN created.` and
   the generated password. **Copy the password, then delete that cron line
   and delete `setup.log`** (it contains the password).
6. The caches and the storage link are done from the website in step 10
   (Admin > System > *Run deploy tasks*).

---

## 9. Add the cron job

The cron job runs CiteVanta's background work every minute: it starts
scheduled scans and processes the queue of scan calls. Without it, scans stay
"queued" forever.

cPanel > **Cron Jobs** > *Add New Cron Job* > **Once Per Minute**, command:

```
/usr/local/bin/php /home/USER/citevanta/artisan citevanta:cron >/dev/null 2>&1
```

That is all. (Admin > System shows this exact line with your real path; copy
it from there if unsure.)

**Fallback, only if command cron jobs are not possible:** a URL cron. Admin >
System shows a second line like
`wget -q -O /dev/null "https://app.DOMAIN/cron/YOUR_CRON_TOKEN"`. Use one or
the other, never both. Web requests are limited to 30 seconds, so the URL
cron does less work per minute; CiteVanta adapts to this automatically.

---

## 10. Log in and check Admin > System

1. Open `https://app.DOMAIN` and log in with the admin from step 8.
2. Open **Admin > System** (sidebar, *Admin* section).
3. **Without SSH:** click **Run deploy tasks** and confirm. It runs
   `migrate --force`, `config:cache`, `route:cache`, `view:cache`,
   `event:cache` and creates the storage link. Every task should show a green
   tick. If the host does not allow symlinks, it says public files are served
   through the `/storage` route instead; that is fine.
   (With SSH you already did this in step 8.)
4. Check the page:
   - **No red "Fix before going live" box.** It appears if `APP_DEBUG` is true
     in production, `CRON_TOKEN` is empty or short, or `OPENAI_API_KEY` is
     empty. Fix `.env`, then run the deploy tasks again (or
     `php artisan config:cache` with SSH).
   - **Cron > Last run:** less than a minute ago, mode **cli unlimited**,
     scheduler **in-process** (because `proc_open` is disabled on this host).
     If it says "Cron may not be running", check step 9.
   - **Queue driver:** database. **Failed jobs:** 0.
5. Create your team's account for real use: log out, **Register** with the
   hotel team's email (or use your admin account), then **Add hotel** and go
   through the setup wizard.

> Each user registers and gets their own team on a 7-day trial. Plans are not
> billed yet; change a team's plan in the database (`teams.plan`: trial,
> starter or pro) if needed.

---

## 11. First real scan checklist

The first real scan costs real money, so start small.

### Step 1: one cheap test scan

This asks each of 10 prompts **once** (about 10 engine calls and 10 analysis
calls, a few cents).

- **With SSH:**

  ```bash
  cd ~/citevanta
  php artisan scan:test "Your Hotel Name" "City" --country="Indonesia" --competitors="Competitor A,Competitor B" --runs=1
  ```

- **Without SSH** (one-off cron line, as in step 8; delete it after one run):

  ```
  cd /home/USER/citevanta && /usr/local/bin/php artisan scan:test "Your Hotel Name" "City" --country="Indonesia" --runs=1 >> storage/logs/scan-test.log 2>&1
  ```

  Wait two minutes, **delete the cron line**, then read
  `storage/logs/scan-test.log` in File Manager. (If the line ran twice, there
  are two reports in the file; that only cost a few cents more.)

Check the report:

- [ ] **Answers saved:** "answers collected 10/10" (or close).
- [ ] **Citations found:** "Top cited domains" lists real sites
      (tripadvisor.com, booking.com, ...).
- [ ] **Hotel names detected:** visibility and share of voice look plausible;
      if the hotel is clearly named in answers but shows 0%, tell me.
- [ ] **Cost recorded:** "Total cost: $0.0x". No "No price configured" warning.
- [ ] **No errors** at the bottom. A line "Model not found: ... Check
      CITEVANTA_SCAN_MODEL" means the model name in `.env` is wrong.

`scan:test` creates its own hotel without a team; it does not appear in any
team's dashboard (admins see it under Admin > Hotels).

### Step 2: a full scan through the dashboard

1. Log in as the hotel team, finish the setup wizard for the hotel. Finishing
   the wizard queues the first scan automatically.
2. Open the hotel dashboard. The banner shows "Scan queued", then
   "Scan running · 12 of 60 answers collected · about N minutes left".
3. The cron processes it in the background (about 50 seconds of work per
   minute). Leave the page open or come back later.
4. When it finishes, the dashboard shows the results. Later scans: the
   **Scan now** button (manual allowance) or the automatic schedule.

### What to send me afterwards

1. The full **scan:test output** (from your terminal or `scan-test.log`).
2. A screenshot of **Admin > System** (hide the URL cron line: it contains
   your CRON_TOKEN).
3. A screenshot of **Admin > Scan runs** showing the dashboard scan
   (trigger, status, answers, failed, cost, duration, and any errors).

---

## 12. Updating the app later

**Back up first:** cPanel > **Backup** > download a **MySQL database** backup
(and keep your `.env` copy).

### With SSH

```bash
cd ~/citevanta
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan up
```

### Without SSH

1. On your computer: `php artisan citevanta:package`.
2. In File Manager, upload the new zip to `/home/USER/citevanta` and
   **Extract** it there, letting it **overwrite** existing files.
   The zip contains no `.env` and no `storage` content, so your settings,
   logs and uploads stay as they are.
3. Delete the zip.
4. Log in and click **Admin > System > Run deploy tasks** (database
   updates and fresh caches). The first request after extracting clears the
   old release's cached config and routes by itself (the zip's
   `release.json`), so the site does not break before you get there. If a
   page still fails because new tables are missing, open `/admin/system`
   directly and run the deploy tasks.

   Releases built before 20 Nov 2026 have no `release.json`: delete the
   `.php` files in `bootstrap/cache/` (keep `.gitignore`) in File Manager first.

> Files deleted in a new version stay on the server when you extract over
> the old one. That is harmless for CiteVanta. For a fully clean update,
> rename `citevanta` to `citevanta-old`, extract the new zip into a fresh
> `citevanta` folder, copy `.env` and `storage/app` across, run the deploy
> tasks, and delete `citevanta-old` once everything works.

---

## 13. Troubleshooting

| Problem | What to do |
| --- | --- |
| **500 error / blank page** | Read `storage/logs/laravel-YYYY-MM-DD.log` (File Manager). Most often: wrong `DB_*` values, missing `APP_KEY`, or `storage` not writable. Never turn on `APP_DEBUG` on the live site. |
| **Changes to `.env` have no effect** | The config is cached. Admin > System > Clear caches, then Run deploy tasks (SSH: `php artisan config:cache`). |
| **"Cron may not be running"** | Check the cron line in cPanel (exact path to `artisan`, `/usr/local/bin/php`). Test it by temporarily replacing `>/dev/null 2>&1` with `>> /home/USER/citevanta/storage/logs/cron.log 2>&1`, wait a minute and read the file. |
| **Scans stay "queued"** | Same as above: the cron is not running. |
| **"Model not found: … Check CITEVANTA_SCAN_MODEL"** | The model name in `.env` is not available to your OpenAI account. Fix `CITEVANTA_SCAN_MODEL` / `CITEVANTA_ANALYSIS_MODEL`, then clear caches and run deploy tasks. |
| **Scan errors mention 401 / "Incorrect API key"** | Check `OPENAI_API_KEY`, then clear caches and run deploy tasks. |
| **No verification / reset emails** | Check `MAIL_*`, try port 587 with `MAIL_SCHEME=smtp`. Check the spam folder. |
| **Styles missing (plain HTML page)** | `public/build` was not uploaded. Rebuild the zip with `php artisan citevanta:package` (SSH: commit `public/build`). |
| **Page redirects in a loop** | SSL is not active for the domain: run AutoSSL (step 5), then try again. |
| **Option B: `/.env` does not show 403** | Stop and fix immediately: the root `.htaccess` is missing or mod_rewrite is off. Move to option A. |
