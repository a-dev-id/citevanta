<?php

namespace App\Services\Ai;

use Closure;
use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\AgentsContract;
use OpenAI\Contracts\Resources\AssistantsContract;
use OpenAI\Contracts\Resources\AudioContract;
use OpenAI\Contracts\Resources\BatchesContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Contracts\Resources\CompletionsContract;
use OpenAI\Contracts\Resources\ContainersContract;
use OpenAI\Contracts\Resources\ConversationsContract;
use OpenAI\Contracts\Resources\DecisionsContract;
use OpenAI\Contracts\Resources\EditsContract;
use OpenAI\Contracts\Resources\EmbeddingsContract;
use OpenAI\Contracts\Resources\FilesContract;
use OpenAI\Contracts\Resources\FineTunesContract;
use OpenAI\Contracts\Resources\FineTuningContract;
use OpenAI\Contracts\Resources\ImagesContract;
use OpenAI\Contracts\Resources\ModelsContract;
use OpenAI\Contracts\Resources\ModerationsContract;
use OpenAI\Contracts\Resources\RealtimeContract;
use OpenAI\Contracts\Resources\ResponsesContract;
use OpenAI\Contracts\Resources\SkillsContract;
use OpenAI\Contracts\Resources\ThreadsContract;
use OpenAI\Contracts\Resources\VaultsContract;
use OpenAI\Contracts\Resources\VectorStoresContract;

/**
 * The OpenAI client every AI class gets. Each call goes to the client of
 * the key in OpenAiKeyScope: the team's own key inside a team's guarded call,
 * otherwise our key (built lazily, so a team-key call never needs ours).
 */
final class ScopedOpenAiClient implements ClientContract
{
    /** @var array<string, ClientContract> */
    private array $teamClients = [];

    private ?ClientContract $ours = null;

    /**
     * @param  Closure(): ClientContract  $ourClient
     */
    public function __construct(
        private readonly OpenAiKeyScope $scope,
        private readonly Closure $ourClient,
    ) {}

    public function client(): ClientContract
    {
        $key = $this->scope->current();

        if ($key === null) {
            return $this->ours ??= ($this->ourClient)();
        }

        return $this->teamClients[hash('sha256', $key)] ??= self::forKey($key);
    }

    public static function forKey(string $key): ClientContract
    {
        return OpenAI::factory()
            ->withApiKey($key)
            ->withHttpClient(new Client(['timeout' => config('openai.request_timeout', 30)]))
            ->make();
    }

    public function completions(): CompletionsContract
    {
        return $this->client()->completions();
    }

    public function responses(): ResponsesContract
    {
        return $this->client()->responses();
    }

    public function conversations(): ConversationsContract
    {
        return $this->client()->conversations();
    }

    public function realtime(): RealtimeContract
    {
        return $this->client()->realtime();
    }

    public function chat(): ChatContract
    {
        return $this->client()->chat();
    }

    public function containers(): ContainersContract
    {
        return $this->client()->containers();
    }

    public function skills(): SkillsContract
    {
        return $this->client()->skills();
    }

    public function agents(): AgentsContract
    {
        return $this->client()->agents();
    }

    public function vaults(): VaultsContract
    {
        return $this->client()->vaults();
    }

    public function decisions(): DecisionsContract
    {
        return $this->client()->decisions();
    }

    public function embeddings(): EmbeddingsContract
    {
        return $this->client()->embeddings();
    }

    public function audio(): AudioContract
    {
        return $this->client()->audio();
    }

    public function edits(): EditsContract
    {
        return $this->client()->edits();
    }

    public function files(): FilesContract
    {
        return $this->client()->files();
    }

    public function models(): ModelsContract
    {
        return $this->client()->models();
    }

    public function fineTuning(): FineTuningContract
    {
        return $this->client()->fineTuning();
    }

    public function fineTunes(): FineTunesContract
    {
        return $this->client()->fineTunes();
    }

    public function moderations(): ModerationsContract
    {
        return $this->client()->moderations();
    }

    public function images(): ImagesContract
    {
        return $this->client()->images();
    }

    public function assistants(): AssistantsContract
    {
        return $this->client()->assistants();
    }

    public function threads(): ThreadsContract
    {
        return $this->client()->threads();
    }

    public function batches(): BatchesContract
    {
        return $this->client()->batches();
    }

    public function vectorStores(): VectorStoresContract
    {
        return $this->client()->vectorStores();
    }
}
