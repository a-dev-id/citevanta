<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Ai\TeamAiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings > AI key (owners of private-plan teams): the team's own OpenAI
 * key, tested before it is saved and never shown again, and the team's
 * monthly AI budget for that key.
 */
class AiKeyController extends Controller
{
    public function show(Request $request, TeamAiKey $keys): View
    {
        $team = $this->team($request);

        return view('settings.ai-key', [
            'team' => $team,
            'spent' => $keys->spentThisMonth($team),
            'stopMessage' => $team->openai_key_stopped_at ? TeamAiKey::stopMessage($team->openai_key_stop_code) : null,
        ]);
    }

    public function guide(Request $request): View
    {
        $this->team($request);

        return view('settings.ai-key-guide');
    }

    public function save(Request $request, TeamAiKey $keys): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate(['key' => ['required', 'string', 'min:20', 'max:300', 'starts_with:sk-']], [
            'key.starts_with' => 'OpenAI API keys start with "sk-".',
        ]);

        $result = $keys->save($team, $data['key']);

        return $result['ok']
            ? to_route('settings.ai-key')->with('status', 'Key works. All AI calls of your team now use it.')
            : to_route('settings.ai-key')->with('error', 'The key was not saved: '.$result['error']);
    }

    public function test(Request $request, TeamAiKey $keys): RedirectResponse
    {
        $result = $keys->test($this->team($request));

        return $result['ok']
            ? to_route('settings.ai-key')->with('status', 'Key works.')
            : to_route('settings.ai-key')->with('error', (string) $result['error']);
    }

    public function remove(Request $request, TeamAiKey $keys): RedirectResponse
    {
        $keys->remove($this->team($request));

        return to_route('settings.ai-key')->with('status', 'Key removed. Scans and content stop until you add a key again.');
    }

    public function budget(Request $request, TeamAiKey $keys): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate(['ai_budget_usd' => ['required', 'numeric', 'min:1', 'max:100000']]);

        $raised = (float) $data['ai_budget_usd'] > (float) $team->ai_budget_usd;
        $team->forceFill(['ai_budget_usd' => $data['ai_budget_usd']])->save();

        if ($raised && $keys->spentThisMonth($team) < (float) $team->ai_budget_usd) {
            $keys->resume($team);
        }

        return to_route('settings.ai-key')->with('status', 'Monthly budget saved.');
    }

    private function team(Request $request): Team
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->ownsTeam($team) && $team->isPrivate(), 403);

        return $team;
    }
}
