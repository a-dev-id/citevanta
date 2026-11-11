<?php

namespace App\Services;

use App\Models\AiResponse;
use App\Models\PilotResultReview;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PilotQuality
{
    public function __construct(private VisibilityAnalysis $analysis, private CitationAnalysis $sources) {}

    public static function hash(mixed $value): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }

    public function inspect(TestRun $run): array
    {
        $run->load(['response.citations', 'response.assessments.hotel', 'usage', 'cost', 'batch', 'provider']);
        $response = $run->response;
        $raw = is_array($response?->raw_metadata) ? $response->raw_metadata : [];
        $valid = $response && $raw && $this->analysis->isValid($response);
        if ($valid) {
            $this->analysis->preview($response);
            $response->loadMissing(['assessments.hotel', 'citations']);
        }
        $text = '';
        $expected = [];
        foreach (is_array($raw['output'] ?? null) ? $raw['output'] : [] as $message) {
            if (! is_array($message) || ($message['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'output_text') {
                    continue;
                }
                if ($text !== '') {
                    $text .= "\n\n";
                }
                $offset = mb_strlen($text);
                $text .= $part['text'] ?? '';
                foreach ($part['annotations'] ?? [] as $citation) {
                    if (($citation['type'] ?? null) !== 'url_citation' || ! $this->sources->normalize($citation['url'] ?? '')) {
                        continue;
                    }
                    $expected[] = ['url' => $citation['url'], 'title' => $citation['title'] ?? null, 'start_index' => isset($citation['start_index']) ? $offset + $citation['start_index'] : null, 'end_index' => isset($citation['end_index']) ? $offset + $citation['end_index'] : null];
                }
            }
        }
        $stored = $response?->citations->map(fn ($c) => $c->only(['url', 'title', 'start_index', 'end_index']))->all() ?? [];
        $rangeOk = collect($stored)->every(fn ($c) => $this->sources->normalize($c['url']) && (($c['start_index'] === null && $c['end_index'] === null) || (is_int($c['start_index']) && is_int($c['end_index']) && $c['start_index'] >= 0 && $c['end_index'] > $c['start_index'] && $c['end_index'] <= mb_strlen($text))));
        $expected = array_map(fn ($c) => self::hash($c), $expected);
        $stored = array_map(fn ($c) => self::hash($c), $stored);
        sort($expected);
        sort($stored);
        $item = $run->batch?->items()->where('id', $run->batch_item_id)->first();
        $checks = [
            'source_snapshot_matches' => $item && $item->test_run_id === $run->id && $item->search_prompt_id === $run->search_prompt_id && $run->prompt_text === ($item->prompt_snapshot['text'] ?? null) && $run->prompt_version === ($item->prompt_snapshot['version'] ?? null) && (int) $run->repetition === 1 && $run->ai_provider_id === $run->batch?->ai_provider_id && $run->model === $run->batch?->model,
            'valid_completed_response' => (bool) $valid,
            'saved_answer_matches_raw' => $response && $text !== '' && $response->text === $text,
            'response_identity_matches' => $response && $response->api_response_id && ($raw['id'] ?? null) === $response->api_response_id && in_array($raw['model'] ?? null, $run->batch?->pricing_snapshot['rates']['aliases'] ?? [$run->model], true),
            'unique_response_identity' => $response && $response->api_response_id && ! AiResponse::where('api_response_id', $response->api_response_id)->where('id', '!=', $response->id)->whereHas('run', fn ($q) => $q->where('test_batch_id', $run->test_batch_id))->exists(),
            'citation_integrity' => $expected === $stored && $rangeOk,
            'request_evidence_matches' => $run->provider?->identifier === 'openai' && ($run->request_settings['input'] ?? null) === $run->prompt_text && ($run->request_settings['tools'][0]['type'] ?? null) === 'web_search' && ($run->request_settings['max_tool_calls'] ?? null) === 1 && ($run->request_settings['tool_choice'] ?? null) === 'required' && ($run->request_settings['tools'][0]['user_location'] ?? null) === ($run->market_snapshot['search_settings'] ?? null),
        ];
        $warnings = [];
        if ($response && $response->citations->isEmpty()) {
            $warnings[] = 'No citations saved. This is an observed citation absence, not a fabricated zero-cost result.';
        }
        if ($response?->unresolved_hotels) {
            $warnings[] = 'Unresolved or ambiguous identities require inspection in the existing analysis review.';
        }
        if (! $run->usage || $run->usage->total_tokens === null) {
            $warnings[] = 'Token usage is unavailable.';
        }
        if (! $run->cost || $run->cost->usage_cost_micros === null) {
            $warnings[] = 'Usage cost is unknown; any safety reservation remains separate from observed cost.';
        }
        $fingerprint = self::hash([$checks, $run->only(['id', 'status', 'prompt_text', 'prompt_version', 'market_snapshot', 'request_settings']), $response?->only(['text', 'raw_metadata', 'api_response_id', 'status', 'error_details']), $response?->assessments->sortBy('id')->map(fn ($a) => $a->only(['id', 'hotel_id', 'mentioned', 'recommended', 'rank', 'manual', 'supporting_citation_ids', 'evidence', 'review_note']))->values()->all(), $response?->citations->sortBy('id')->map(fn ($c) => $c->only(['id', 'url', 'title', 'start_index', 'end_index', 'classification', 'hotel_id', 'manual', 'review_note']))->values()->all()]);

        return ['checks' => $checks, 'can_approve' => ! in_array(false, $checks, true), 'warnings' => $warnings, 'fingerprint' => $fingerprint];
    }

    public function isApproved(TestRun $run): bool
    {
        if (! $run->batch?->items()->where('id', $run->batch_item_id)->where('test_run_id', $run->id)->where('status', 'completed')->exists()) {
            return false;
        }
        $review = PilotResultReview::where('test_run_id', $run->id)->latest('id')->first();
        if (! $review || $review->decision !== 'approved') {
            return false;
        }
        $inspection = $this->inspect($run);

        return $inspection['can_approve'] && hash_equals($review->evidence_fingerprint, $inspection['fingerprint']);
    }

    public function review(TestRun $run, User $user, string $decision, ?string $note, string $fingerprint, bool $acknowledged): PilotResultReview
    {
        app(TenantContext::class)->assertManager($user);
        $run = TestRun::findOrFail($run->id);
        abort_unless($run->batch?->pilot_key === config('pilot.key'), 404);
        abort_unless($run->batch->items()->where('id', $run->batch_item_id)->where('test_run_id', $run->id)->exists(), 422, 'Only the saved result of a pilot item can be reviewed.');

        return DB::transaction(function () use ($run, $user, $decision, $note, $fingerprint, $acknowledged) {
            TestRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $quality = $this->inspect($run->fresh());
            if (! hash_equals($quality['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['quality' => 'Evidence changed. Reopen the report before reviewing.']);
            }
            if (! in_array($decision, ['approved', 'flagged'], true) || ($decision === 'approved' && (! $quality['can_approve'] || ! $acknowledged)) || ($decision === 'flagged' && mb_strlen(trim($note ?? '')) < 5)) {
                throw ValidationException::withMessages(['quality' => 'Approval requires valid integrity checks and explicit evidence review. Flagging requires a reason of at least five characters.']);
            }

            return PilotResultReview::create(['test_batch_id' => $run->test_batch_id, 'test_run_id' => $run->id, 'reviewed_by' => $user->id, 'decision' => $decision, 'note' => $note, 'evidence_fingerprint' => $quality['fingerprint'], 'checks' => ['automatic' => $quality['checks'], 'evidence_reviewed' => $acknowledged]]);
        });
    }
}
