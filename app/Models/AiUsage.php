<?php

namespace App\Models;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The measured cost of one AI call (from the API's usage: tokens, including
 * search content tokens, and the web_search_call items in the output).
 * Source of truth for the budget buckets (BudgetGuard).
 *
 * @property int $id
 * @property AiBucket $bucket
 * @property bool $billed_to_team paid with the team's own OpenAI key
 * @property AiCallKind $kind
 * @property int|null $team_id
 * @property int|null $hotel_id
 * @property int|null $scan_run_id
 * @property string $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $web_searches
 * @property float $cost_usd
 * @property CarbonInterface $created_at
 */
#[Fillable(['bucket', 'billed_to_team', 'kind', 'team_id', 'hotel_id', 'scan_run_id', 'model', 'tokens_in', 'tokens_out', 'web_searches', 'cost_usd', 'created_at'])]
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'bucket' => AiBucket::class,
            'kind' => AiCallKind::class,
            'billed_to_team' => 'boolean',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'web_searches' => 'integer',
            'cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
