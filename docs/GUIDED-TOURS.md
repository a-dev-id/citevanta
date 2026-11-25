# Guided tours (onboarding)

Short tours of the main pages, in English and Indonesian. One step highlights one element (the page is dimmed, a clear outline surrounds the element) and a popover next to it has a title, a short text, a counter ("2 of 6") and the buttons Previous, Next (the last step says Done), Skip and the checkbox "Don't show this again". Esc closes it (like Skip), the left and right arrows move between steps, and Tab stays inside the popover. `prefers-reduced-motion` is respected.

The tours are our own small script (`resources/js/tour.js`), no library, no tracking. The server decides which tour a page has and writes its text in the user's language; the browser only shows it and reports **Done** or **Skip**.

## The tours

| Tour | Pages (route names) | Steps |
| --- | --- | --- |
| `dashboard` | `hotels.show` | hotel switcher, visibility score, trend chart, last scan, the menu, locked features (trial only), help button |
| `recommendations` | `hotels.recommendations` | ranked list, quick wins, "Looks done", effort and impact, content buttons, copy and paste, locked recommendations (trial only) |
| `prompts-scans` | `hotels.prompts`, `hotels.scans` | what prompts are, suggested prompts, run a scan (and what it costs the plan), scan history, what the status and progress mean |
| `reports` (Private plan only) | `hotels.reports` | date picker, compare, KPI cards, tables, CSV export, Searches by date |
| `settings` (Private plan only) | `settings.ai-key`, `settings.google` | AI key, monthly budget, share Google, pick the property, the Settings menu |

A step whose element is not on the page, or is hidden, is skipped when the tour runs (also when a Livewire re-render removes it). A step in the sidebar opens the drawer on a narrow screen and closes it again afterwards; if it is inside a collapsed submenu it is skipped.

## When a tour starts by itself

The first time a user opens a page that has a tour, when **their email is verified** and **their team has a hotel that is set up**. Never on an admin page, never on top of an open modal, and never while a form has unsaved input (the user can still start it from the help button).

## What is remembered

Per user, in the table `user_tours` (tour, version, status, skip count), so it follows the user across devices. Nothing else about what the user does is stored.

- **Done**, or closing with "Don't show this again" ticked: the tour is finished.
- **Skip** (or Esc, or the X) without the checkbox: the tour comes back on the next visit to that page, **at most 2 more times**, then it stops by itself.
- **Version:** each tour has a version in `App\Services\Tours\TourDefinitions`. Raise it when you add steps: everyone, including users who finished it, is offered the tour once more (skips of an older version do not count).
- Skipping a tour that was replayed from the help button does not undo "finished".

## Help button

The "?" in the header has **Take the tour of this page** and **Restart all tours** (every tour is offered again as the user visits each page).

## Plans

- A tour or step marked `plan: private` is shown to teams on the Private plan only (Reports, Connect Google, AI key).
- A step marked `plan: trial` (about locked features) is shown to teams on the trial plan only.

## Switching tours off, and resetting

- `.env`: `TOURS_ENABLED=false` switches every tour and the help button off (default `true`). Run "Clear caches" on Admin > System after changing it.
- **Admin > Teams > Reset tours:** pick a member of the team and press Reset tours; that user sees every tour again.

## Adding or changing a tour

1. Add the steps in `app/Services/Tours/TourDefinitions.php`: an id, the CSS selector of the element (we use `[data-tour="..."]` attributes in the views) and optionally `sidebar` or `plan`.
2. Add the text in `lang/en/tours.php` and `lang/id/tours.php` under `{tour}.steps.{id}` (a title and a text). A test checks that every step has both languages and that every selector exists in the views.
3. Raise the tour's `version` if existing users should see it again.

The language is the app locale (`APP_LOCALE`, `en` or `id`).
