<?php

/*
| First-time setup checklist (see App\Services\Setup\SetupChecklist). Every item has a short title, one line of help
| and the text of its button. Items tick by themselves from real data.
*/

return [
    'ui' => [
        'region' => 'Setup checklist',
        'title' => 'Get started',
        'progress' => ':done of :total done',
        'pill' => 'Setup :done/:total',
        'pill_all' => 'All set',
        'minimize' => 'Minimize the checklist',
        'expand' => 'Open the setup checklist',
        'close' => 'Close the checklist',
        'menu' => 'Checklist menu',
        'hide' => 'Hide this checklist',
        'all_set' => 'All set',
        'all_set_text' => 'You have finished the setup. This checklist hides itself after this visit. You can bring it back from the ? menu.',
        'done' => 'Done',
        'todo' => 'Not done yet',
        'not_counted' => 'Not part of the progress',
        'help_entry' => 'Setup checklist',
    ],

    'items' => [
        'email' => ['title' => 'Verify your email', 'help' => 'Your email address is confirmed.', 'action' => 'Verify'],
        'hotel' => ['title' => 'Add your hotel', 'help' => 'Name, city and website of your hotel.', 'action' => 'Add hotel'],
        'facts' => ['title' => 'Fill in the hotel facts', 'help' => 'AI answers are only as good as your facts: add at least the price range and the number of rooms.', 'action' => 'Add facts'],
        'competitors' => ['title' => 'Add at least 3 competitors', 'help' => 'The hotels travellers compare you with.', 'action' => 'Add competitors'],
        'prompts' => ['title' => 'Review your prompts', 'help' => 'Keep at least 10 prompts active: the questions travellers ask AI.', 'action' => 'Review prompts'],
        'scan' => ['title' => 'Run your first scan', 'help' => 'See how AI assistants talk about your hotel.', 'action' => 'Scan now'],
        'recommendation' => ['title' => 'Open your first recommendation', 'help' => 'Open one and mark it To do, In progress or Done.', 'action' => 'See recommendations'],
        'openai_key' => ['title' => 'Add your OpenAI key and budget', 'help' => 'Your own key, tested, with a monthly limit.', 'action' => 'Add key'],
        'google' => ['title' => 'Connect Google', 'help' => 'Share Google Analytics and Search Console and pick your property.', 'action' => 'Connect Google'],
        'script' => ['title' => 'Install the website script', 'help' => 'Counts AI visits and booking clicks on your own website.', 'action' => 'Get the script'],
        'health' => ['title' => 'Check your tracking health', 'help' => 'The tracking check shows no errors.', 'action' => 'Open the check'],
        'upgrade' => ['title' => 'Upgrade when you are ready', 'help' => 'Choosing a plan unlocks everything that is locked in your trial.', 'action' => 'See plans'],
    ],
];
