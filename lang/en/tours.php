<?php

/*
| Guided tours (see App\Services\Tours\TourDefinitions). Each step has a short title and a friendly text.
*/

return [
    'ui' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'done' => 'Done',
        'skip' => 'Skip',
        'dont_show' => "Don't show this again",
        'counter' => ':current of :total',
        'close' => 'Close the tour',
        'dialog' => 'Guided tour',
        'help' => 'Help and tours',
        'take_tour' => 'Take the tour of this page',
        'no_tour' => 'No tour for this page',
        'restart_all' => 'Restart all tours',
        'restarted' => 'Done. The tours will show again as you visit each page.',
        'nothing' => 'Nothing to show on this page right now.',
    ],

    'dashboard' => [
        'name' => 'Dashboard',
        'steps' => [
            'switcher' => ['title' => 'Switch hotel', 'text' => 'If your team has more than one hotel, pick the one you want to look at here. Everything on the page follows your choice.'],
            'score' => ['title' => 'Your visibility score', 'text' => 'This is how often AI assistants mention your hotel when travellers ask about your destination, with the change since the previous scan.'],
            'trend' => ['title' => 'The trend', 'text' => 'Each point is one scan. A line that goes up means more travellers hear about your hotel. Switch to "By persona" to see different kinds of guests.'],
            'last_scan' => ['title' => 'Last scan', 'text' => 'When your hotel was last checked and when the next scan is planned. Use "Scan now" next to it to check again.'],
            'menu' => ['title' => 'Your menu', 'text' => 'Prompts are the questions we ask. Scans are the checks. Citations show which websites AI trusts. Insights explain why competitors win. Recommendations tell you what to fix first.'],
            'trial' => ['title' => 'During your trial', 'text' => 'Some features are locked while you try the product, for example most recommendation details. Choosing a plan unlocks them.'],
            'help' => ['title' => 'Help is always here', 'text' => 'Click the question mark to take the tour of any page again, or to restart all tours.'],
        ],
    ],

    'recommendations' => [
        'name' => 'Recommendations',
        'steps' => [
            'list' => ['title' => 'A ranked to-do list', 'text' => 'Every recommendation comes from your scans. The list is ordered so that what helps most, and is quickest to do, comes first.'],
            'quick_wins' => ['title' => 'Quick wins', 'text' => 'High impact and an hour of work or less. If you only have time for a few things, start here.'],
            'looks_done' => ['title' => '"Looks done"', 'text' => 'When a later scan shows that a problem is gone, we mark the recommendation "Looks done". Confirm it, or say "Not yet" if you disagree.'],
            'effort_impact' => ['title' => 'Effort and impact', 'text' => 'Effort is how long it takes, impact is how much it can improve your visibility. Use the filters to find small jobs with a big effect.'],
            'content' => ['title' => 'Open a recommendation', 'text' => 'Click a row to read what to change and why. Inside, the content buttons write the text for you, for example a page section or an answer for your website.'],
            'paste' => ['title' => 'Copy, paste, done', 'text' => 'The content comes ready to copy. The line "Where" tells you where to paste it: your website, Google Business Profile or a booking site. Then set the status to Done.'],
            'locked' => ['title' => 'Locked during the trial', 'text' => 'On the trial you see the title and category of each recommendation. Choosing a plan opens the full steps and the content writer.'],
        ],
    ],

    'prompts-scans' => [
        'name' => 'Prompts and scans',
        'steps' => [
            'prompts' => ['title' => 'What prompts are', 'text' => 'Prompts are the questions travellers ask AI assistants, like "best boutique hotel in Ubud". We never put your hotel name in them, so the answers are honest. Changes save automatically.'],
            'regenerate' => ['title' => 'Suggested prompts', 'text' => 'We suggest prompts for your hotel. "Regenerate" replaces the suggestions you did not edit; your own and edited prompts are kept.'],
            'run_scan' => ['title' => 'Run a scan', 'text' => 'Press "Scan now" on the dashboard, or wait for the scheduled scan. Each manual scan uses one of the scans your plan includes each month; the dashboard shows how many are left.'],
            'history' => ['title' => 'Your scan history', 'text' => 'Every scan is listed here. Filter by status or by who started it, and open a scan to see its answers.'],
            'progress' => ['title' => 'What the status means', 'text' => 'Queued is waiting, running means answers are being collected (the bar shows how many are done), completed means the results are ready, failed means something went wrong with that scan.'],
        ],
    ],

    'reports' => [
        'name' => 'Reports',
        'steps' => [
            'date_picker' => ['title' => 'Pick the dates', 'text' => 'Choose a preset like "Last 28 days" or open the calendar for a custom range. The range always stays inside the days we have synced.'],
            'compare' => ['title' => 'Compare', 'text' => 'Compare with the previous period or the same period last year. The dashed line in the charts and the % on the cards show the change. An option is greyed out when we do not have data that far back.'],
            'kpi' => ['title' => 'The key numbers', 'text' => 'Visits, purchases and revenue that came from AI assistants, and their share of all your traffic. The small line under each number is the change against your comparison.'],
            'tables' => ['title' => 'The tables', 'text' => 'See which AI platform, country and landing page brings the visitors. Click a column title to sort over all rows, and use the page controls at the bottom.'],
            'csv' => ['title' => 'Download as CSV', 'text' => 'The CSV button gives you every row of the selected dates, not only the page you see, ready for a spreadsheet.'],
            'searches' => ['title' => 'Searches by date', 'text' => 'The dates guests searched for in your booking engine. These are searches, not bookings: a busy date tells you where demand is building.'],
        ],
    ],

    'settings' => [
        'name' => 'Settings',
        'steps' => [
            'ai_key' => ['title' => 'Your OpenAI key', 'text' => 'On the Private plan every AI call of your team uses your own OpenAI key, so you pay OpenAI directly. Paste it here; it is stored encrypted and only the last 4 characters are ever shown.'],
            'budget' => ['title' => 'Monthly budget', 'text' => 'A safety limit for your own key, on top of the spend limit in OpenAI. Set the most you want AI work to cost in a month; the bar shows how much you have used.'],
            'google_share' => ['title' => 'Share Google with us', 'text' => 'Give our service account read-only access to your Google Analytics and Search Console. Copy the email and add it as a viewer; we can never change anything.'],
            'google_pick' => ['title' => 'Pick your property', 'text' => 'Press "Check connection", then choose your GA4 property and Search Console site. The first data arrives within a few hours.'],
            'google_menu' => ['title' => 'Back here any time', 'text' => 'Connect Google, the AI key and the other settings live in the Settings menu.'],
        ],
    ],
];
