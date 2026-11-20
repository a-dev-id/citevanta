<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Search Console clicks and impressions per day by query, page, country and device (synced daily).
 */
#[Table('gsc_daily')]
class GscDaily extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
