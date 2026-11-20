<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * GA4 sessions per day by source, medium, country, landing page and device (synced daily).
 */
#[Table('ga4_traffic_daily')]
class Ga4TrafficDaily extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
