<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * GA4 event counts per day (synced daily).
 */
#[Table('ga4_events_daily')]
class Ga4EventDaily extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
