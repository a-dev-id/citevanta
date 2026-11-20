<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One webhook from Xendit as received (see XenditWebhookController).
 *
 * @property int $id
 * @property string $event_key
 * @property string $event
 * @property string|null $object_id
 * @property string $status received|processed|ignored|failed
 * @property array<string, mixed> $payload
 * @property string|null $error
 * @property CarbonInterface|null $processed_at
 */
#[Fillable(['event_key', 'event', 'object_id', 'status', 'payload', 'error', 'processed_at'])]
class XenditEvent extends Model
{
    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return (array) ($this->payload['data'] ?? []);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
