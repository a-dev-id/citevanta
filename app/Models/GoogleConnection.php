<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The one Google "agent" account the app reads GA4 and Search Console with.
 * Tokens are encrypted and never shown.
 *
 * @property int $id
 * @property string|null $email
 * @property string $refresh_token
 * @property string|null $access_token
 * @property CarbonInterface|null $access_expires_at
 * @property string|null $scopes
 * @property string $status ok|error
 * @property string|null $error
 * @property CarbonInterface|null $connected_at
 * @property CarbonInterface|null $last_success_at
 * @property CarbonInterface|null $failure_alerted_at
 */
#[Hidden(['refresh_token', 'access_token'])]
#[Fillable(['email', 'refresh_token', 'access_token', 'access_expires_at', 'scopes', 'status', 'error', 'connected_at', 'last_success_at', 'failure_alerted_at'])]
class GoogleConnection extends Model
{
    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }

    public function isWorking(): bool
    {
        return $this->status === 'ok';
    }

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_success_at' => 'datetime',
            'failure_alerted_at' => 'datetime',
        ];
    }
}
