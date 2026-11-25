# Setup checklist (first-time setup)

A floating card at the bottom right of the customer app that guides a new team through the setup. Every item **ticks by itself from real data**: nobody ticks anything by hand. The items are recomputed on every page load and a moment after each Livewire action on the page.

## Look and behaviour

- **Expanded:** a card about 320 px wide with a dark header "Get started", a progress bar and "3 of 8 done", then the items. Each item has a checkbox icon, a title, one line of help and a button that goes to the right page. The header has a minimize button and a menu with "Hide this checklist".
- **Minimized:** a small pill, "Setup 3/8", with the progress as a ring. Click it to expand.
- **Narrow screen:** only the pill shows; clicking it opens the card as a bottom sheet (Esc or the minimize button closes it).
- **Remembered per user and per team** in the table `setup_checklist_states` (expanded, minimized or hidden). Default: expanded on the first visit, then as the user left it. Esc minimizes it.
- **Help menu:** "Setup checklist" in the "?" menu brings it back.
- It is **not shown** on admin pages, to users whose email is not verified, or while a guided tour runs (it is hidden for the duration of the tour and comes back afterwards). It floats below modals and the tour, and the page keeps room at the bottom for its own pagination and Save buttons.
- Accessible: it is a labelled region, the buttons have visible focus, Esc minimizes it, and reduced motion is respected.

## The items and when each is done

For every team (items about a hotel use the user's current hotel):

| # | Item | Done when |
| --- | --- | --- |
| 1 | Verify your email | the email is verified |
| 2 | Add your hotel | the team has a hotel with a name, a city and a website |
| 3 | Fill in the hotel facts | the required facts have a value: `Price range per night` and `Number of rooms` (config `citevanta.setup_checklist.required_facts`, compared without case) |
| 4 | Add at least 3 competitors | the hotel has 3 or more competitors |
| 5 | Review your prompts | the hotel has 10 or more **active** prompts |
| 6 | Run your first scan | one scan has completed (also "completed with errors") |
| 7 | Open your first recommendation | one recommendation is no longer "new": it was set to To do, In progress, Done or Dismissed (the app does not record that a row was merely opened) |

Extra items on the **Private plan**:

| # | Item | Done when |
| --- | --- | --- |
| 8 | Add your OpenAI key, with the budget | the team's key passed its test and is not stopped, and a monthly budget above 0 is set |
| 9 | Connect Google | a GA4 property **and** a Search Console site are connected |
| 10 | Install the website script | the script sent its first event, or Check installation passed |
| 11 | Tracking health | the tracking health check has run and none of its results is red (amber is a warning, not an error) |

**Trial teams** also get "Upgrade when you are ready" at the end. It links to Billing (owners only) and is **not counted** in the progress.

## All set

When every counted item is done, the checklist shows **"All set"** on the next visit and then hides itself for good. "Setup checklist" in the help menu brings it back (and "All set" shows once more).

## Switching it off, and resetting

- `.env`: `SETUP_CHECKLIST_ENABLED=false` switches the checklist and its help entry off (default `true`). Run "Clear caches" on Admin > System after changing it.
- **Admin > Teams > Reset checklist** (next to Reset tours): pick a member of the team; their checklist starts again as on a first visit.

## Texts

`lang/en/checklist.php` and `lang/id/checklist.php`: the interface texts under `ui` and, per item, a `title`, a `help` line and an `action` (button text). A test checks every item and every interface text in both languages. The language is the app locale (`APP_LOCALE`).

## Code

`App\Services\Setup\SetupChecklist` (items, done rules, saved state), `App\Livewire\SetupChecklist` (the card), `resources/views/livewire/setup-checklist.blade.php`, `resources/js/checklist.js` (refresh after Livewire actions, restore from the help menu) and the `.cv-checklist` styles in `resources/sass/_components.scss`.
