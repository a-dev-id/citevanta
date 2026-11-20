<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Plan;
use App\Services\Billing\Money;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice for one billing period, an upgrade, or a first payment.
 * Amounts are integers in minor units (rupiah for IDR, cents for USD).
 *
 * @property int $id
 * @property int $team_id
 * @property string $number
 * @property InvoiceKind $kind
 * @property InvoiceStatus $status
 * @property string $provider
 * @property bool $automatic
 * @property string|null $provider_reference
 * @property string|null $provider_payment_id
 * @property string|null $payment_url
 * @property string $description
 * @property Plan $plan
 * @property BillingInterval $interval
 * @property int $hotels
 * @property int $months
 * @property string $currency
 * @property CarbonInterface|null $period_starts_at
 * @property CarbonInterface|null $period_ends_at
 * @property int $unit_price
 * @property int $subtotal
 * @property int $discount_percent
 * @property int $discount
 * @property float $tax_rate
 * @property bool $tax_included
 * @property int $tax
 * @property int $total
 * @property int $unique_code
 * @property int $amount_due
 * @property CarbonInterface $due_at
 * @property CarbonInterface|null $submitted_at
 * @property string|null $proof_path
 * @property string|null $proof_name
 * @property CarbonInterface|null $paid_at
 * @property string|null $payment_note
 * @property CarbonInterface|null $rejected_at
 * @property string|null $rejected_reason
 * @property CarbonInterface|null $reminder_sent_at
 * @property CarbonInterface|null $due_reminder_sent_at
 * @property-read Team $team
 */
#[Fillable([
    'team_id', 'number', 'kind', 'status', 'provider', 'automatic', 'provider_reference', 'provider_payment_id',
    'payment_url', 'description', 'plan', 'interval', 'hotels', 'months', 'currency', 'period_starts_at',
    'period_ends_at', 'unit_price', 'subtotal', 'discount_percent', 'discount', 'tax_rate', 'tax_included', 'tax',
    'total', 'unique_code', 'amount_due', 'due_at', 'submitted_at', 'proof_path', 'proof_name', 'paid_at',
    'marked_paid_by', 'payment_note', 'rejected_at', 'rejected_reason', 'reminder_sent_at', 'due_reminder_sent_at',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function markedPaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_paid_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', InvoiceStatus::openValues());
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at->isPast();
    }

    public function format(int $amount): string
    {
        return Money::format($amount, $this->currency);
    }

    /** Net amount before tax (after discount). */
    public function net(): int
    {
        return $this->total - $this->tax;
    }

    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'plan' => Plan::class,
            'interval' => BillingInterval::class,
            'automatic' => 'boolean',
            'hotels' => 'integer',
            'months' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
            'discount_percent' => 'integer',
            'discount' => 'integer',
            'tax_rate' => 'float',
            'tax_included' => 'boolean',
            'tax' => 'integer',
            'total' => 'integer',
            'unique_code' => 'integer',
            'amount_due' => 'integer',
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'due_reminder_sent_at' => 'datetime',
        ];
    }
}
