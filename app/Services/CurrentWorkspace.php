<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Improvement;
use App\Models\MonitoredBrand;
use Illuminate\Database\Eloquent\Builder;

class CurrentWorkspace
{
    public function hotel(): Hotel
    {
        return Hotel::where('identifier', $this->brand()->slug)->firstOrFail();
    }

    public function brand(): MonitoredBrand
    {
        return app(TenantContext::class)->brand();
    }

    public function improvements(): Builder
    {
        return Improvement::where('hotel_id', $this->hotel()->id);
    }

    public function shortName(): string
    {
        return $this->brand()->short_name ?: $this->brand()->name;
    }

    public function verifiedPages(): array
    {
        return collect($this->brand()->verified_pages)->filter(fn ($page) => isset($page['url']) && app(CitationAnalysis::class)->matchesWebsite($page['url'], $this->brand()->official_website))->values()->all();
    }

    public function canManage(): bool
    {
        return app(TenantContext::class)->canManage(auth()->user());
    }
}
