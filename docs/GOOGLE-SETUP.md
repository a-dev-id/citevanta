# Google setup (GA4 and Search Console for the Private plan)

The app reads Google Analytics 4 and Search Console through **one Google account that you own**, the "agent" (for example `citevanta-view-agent@citevanta.com`, or for testing any Google account of yours). Hotels share read access with that email. You connect the agent to the app **once**; after that the app reads every property and site shared with it.

APIs used (read-only):

| API | Version | Used for |
| --- | --- | --- |
| Google Analytics Data API | v1beta (`properties/{id}:runReport`) | Daily traffic, events, health checks |
| Google Analytics Admin API | v1beta (`accountSummaries`, `properties/{id}`, `dataStreams`, `customDimensions`) | Finding the hotel's property, booking date parameters |
| Search Console API | webmasters/v3 (`sites`, `searchAnalytics/query`) | Daily queries, pages, countries, devices |

Scopes: `https://www.googleapis.com/auth/analytics.readonly` and `https://www.googleapis.com/auth/webmasters.readonly` (read only), plus `openid` and `email` so the app can check that the agent account signed in. Nothing else.

> Search Console's AI Overviews / AI Mode numbers are **not** available through the API (checked 20 Nov 2026), so the app does not show them.

## 1. Create the agent Google account

- With Google Workspace: create a user, for example `citevanta-view-agent@citevanta.com`.
- Without Workspace (testing): any Google account you own.

Put the email in `.env` later as `GOOGLE_AGENT_EMAIL`. This is the email hotels add in GA4 and Search Console.

## 2. Create the Google Cloud project

1. Go to <https://console.cloud.google.com/> and sign in (with your own admin account, not necessarily the agent).
2. Top bar > project picker > **New project**. Name: the product name (for example "CiteVanta"). Create and select it.

## 3. Enable the three APIs

**APIs & Services > Library**, search and click **Enable** for each:

1. **Google Analytics Data API**
2. **Google Analytics Admin API**
3. **Google Search Console API**

## 4. Configure the OAuth consent screen

**APIs & Services > OAuth consent screen** (or **Google Auth Platform > Branding / Audience**):

- **User type**
  - **Internal** if the agent is a Google Workspace user in your own Workspace. Internal apps need no verification and tokens do not expire after 7 days.
  - **External** otherwise. Add the agent email under **Test users**.
- App name: the product name. Support email: yours. Authorised domain: your app domain (for example `a-dashboard.com`, later `citevanta.com`).
- **Scopes** (Data access): add `openid`, `.../auth/userinfo.email`, `.../auth/analytics.readonly` and `.../auth/webmasters.readonly`.

**Important for External apps:** while the publishing status is **Testing**, Google lets the refresh token expire after **7 days**. The app then emails the admins and shows a red banner; click **Reconnect** in Admin > Integrations > Google. To stop this, click **Publish app** on the consent screen. Google may ask for verification because these are Google user-data scopes; you can keep using it as "unverified" for your own agent account.

## 5. Create the OAuth client

**APIs & Services > Credentials > + Create credentials > OAuth client ID**:

- Application type: **Web application**
- Name: "Agent connection"
- **Authorised redirect URIs**: the URI shown on Admin > Integrations > Google, which is
  `APP_URL/admin/integrations/google/callback`, for example
  `https://citevanta.a-dashboard.com/admin/integrations/google/callback`
  (when the app moves to `citevanta.com`, add `https://citevanta.com/admin/integrations/google/callback` too and reconnect).
- Create, then copy the **Client ID** and **Client secret**.

## 6. Fill in .env

```
GOOGLE_CLIENT_ID=123456789-xxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
GOOGLE_AGENT_EMAIL=citevanta-view-agent@citevanta.com
```

Without `GOOGLE_CLIENT_ID` the app uses fake Google data (local development). After changing `.env` on the server: Admin > System > Clear caches.

## 7. Connect the agent

1. Log in to the app as an admin: **Admin > Integrations > Google > Connect agent account**.
2. Google asks you to sign in: choose the **agent** account (the app suggests it) and allow read-only access.
3. Back in the app the status shows **Connected** with the connected account. If you signed in with another account, the app refuses it ("Please sign in as ..."), revokes that token and you can try again.

The refresh token is stored encrypted and never shown. The connection status and the last successful call are on the same page.

## 8. What a hotel does

On **Settings > Connect Google** (Private plan owners) the hotel sees the agent email with a copy button and these steps:

- **GA4**: Admin > Property access management > + > Add users > paste the email > role **Viewer** > untick "Notify new users by email" > Add.
- **Search Console**: Settings > Users and permissions > Add user > paste the email > Permission **Restricted** > Add.

Then **Check connection** lists the property and site that match the hotel website. The owner picks them; the tracking health check and the first sync start (GA4: last 13 months, to compare this year with last year; Search Console: last 16 months, one job per day; then daily).

## Quotas

The sync makes one API call per job and at most 30 calls a minute (`citevanta.google.requests_per_minute`). GA4 standard properties allow 40,000 core tokens per hour and 200,000 per day per property; a typical report costs about 10 tokens. Search Console allows 1,200 queries per minute per site. On a quota error the job waits 15 minutes and retries.
