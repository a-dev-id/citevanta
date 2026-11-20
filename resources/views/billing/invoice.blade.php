@php
    use App\Enums\InvoiceStatus;

    $tz = $team->timezoneName();
    $date = fn ($at) => $at?->copy()->setTimezone($tz)->format('j M Y');
    $rate = rtrim(rtrim(number_format($invoice->tax_rate, 2), '0'), '.');
    $statusLabel = $invoice->isOverdue() ? 'Overdue' : $invoice->status->label();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Invoice {{ $invoice->number }} · {{ config('app.name') }}</title>
    @include('layouts.partials.favicons')
    <style>
        :root { --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --brand: #0074ad; --ok: #15803d; --warn: #b45309; --bad: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: var(--ink); font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .toolbar { max-width: 820px; margin: 16px auto 0; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; padding: 0 16px; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; border: 1px solid var(--brand); background: var(--brand); color: #fff; text-decoration: none; font: inherit; cursor: pointer; }
        .btn-outline { background: #fff; color: var(--brand); }
        header { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; border-bottom: 1px solid var(--line); padding-bottom: 24px; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 8px; }
        .muted { color: var(--muted); }
        .status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; border: 1px solid currentColor; }
        .status-paid { color: var(--ok); } .status-open { color: var(--warn); } .status-bad { color: var(--bad); } .status-void { color: var(--muted); }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 24px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { border-bottom: 0; padding: 6px 8px; }
        tfoot tr.total td { font-weight: 700; font-size: 17px; border-top: 2px solid var(--ink); padding-top: 10px; }
        .box { border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-top: 24px; }
        .alert { max-width: 820px; margin: 16px auto 0; padding: 12px 16px; border-radius: 6px; }
        .alert-ok { background: #ecfdf5; color: var(--ok); } .alert-bad { background: #fef2f2; color: var(--bad); }
        label { display: block; margin: 8px 0 4px; font-weight: 600; }
        .logo { height: 36px; width: auto; }
        @media (max-width: 600px) { .sheet { padding: 20px; margin: 12px; } .parties { grid-template-columns: 1fr; } }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
            .toolbar, .no-print, .alert { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <a href="{{ route('settings.billing') }}" class="btn btn-outline">&larr; Billing</a>
        <button type="button" class="btn btn-outline" onclick="window.print()">Print or save as PDF</button>
    </div>

    @if (session('status'))
        <div class="alert alert-ok" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error') || $errors->any())
        <div class="alert alert-bad" role="alert">{{ session('error') ?? $errors->first() }}</div>
    @endif

    <main class="sheet">
        <header>
            <div>
                <img src="{{ asset('images/logo-600.png') }}" alt="{{ config('app.name') }}" class="logo">
                <p class="muted" style="margin: 12px 0 0; white-space: pre-line">{{ $business['name'] ?? '' }}
{{ $business['address'] ?? '' }}@if (! empty($business['tax_id']))
Tax ID: {{ $business['tax_id'] }}@endif
@if (! empty($business['email'])){{ $business['email'] }}@endif</p>
            </div>
            <div style="text-align: right">
                <h1>Invoice</h1>
                <div><strong>{{ $invoice->number }}</strong></div>
                <div class="muted">Date: {{ $date($invoice->created_at) }}</div>
                <div class="muted">Due: {{ $date($invoice->due_at) }}</div>
                <div style="margin-top: 8px">
                    <span @class([
                        'status',
                        'status-paid' => $invoice->status === InvoiceStatus::Paid,
                        'status-void' => $invoice->status === InvoiceStatus::Void,
                        'status-bad' => $invoice->status === InvoiceStatus::Rejected || $invoice->isOverdue(),
                        'status-open' => in_array($invoice->status, [InvoiceStatus::Pending, InvoiceStatus::Submitted], true) && ! $invoice->isOverdue(),
                    ])>{{ $statusLabel }}</span>
                </div>
            </div>
        </header>

        <section class="parties">
            <div>
                <h2>Bill to</h2>
                <div>{{ $team->name }}</div>
                @if ($owner)<div class="muted">{{ $owner->name }} · {{ $owner->email }}</div>@endif
            </div>
            <div>
                <h2>Period</h2>
                @if ($invoice->period_starts_at && $invoice->period_ends_at)
                    <div>{{ $date($invoice->period_starts_at) }} – {{ $date($invoice->period_ends_at) }}</div>
                @else
                    <div class="muted">Starts on the payment date ({{ $invoice->interval->label() }})</div>
                @endif
            </div>
        </section>

        <table>
            <thead>
                <tr><th scope="col">Description</th><th scope="col" class="num">Amount</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        {{ $invoice->description }}
                        @if ($invoice->kind !== \App\Enums\InvoiceKind::Upgrade)
                            <div class="muted">{{ $invoice->hotels }} × {{ $invoice->format($invoice->unit_price) }} per hotel per month × {{ $invoice->months }} {{ Str::plural('month', $invoice->months) }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $invoice->format($invoice->subtotal) }}</td>
                </tr>
                @if ($invoice->discount > 0)
                    <tr><td>Volume discount ({{ $invoice->discount_percent }}%)</td><td class="num">−{{ $invoice->format($invoice->discount) }}</td></tr>
                @endif
            </tbody>
            <tfoot>
                @if ($invoice->tax_rate > 0)
                    @if ($invoice->tax_included)
                        <tr><td class="num">Total incl. {{ $taxName }} {{ $rate }}%</td><td class="num">{{ $invoice->format($invoice->total) }}</td></tr>
                        <tr><td class="num muted">of which {{ $taxName }} {{ $rate }}%</td><td class="num muted">{{ $invoice->format($invoice->tax) }}</td></tr>
                    @else
                        <tr><td class="num">Subtotal</td><td class="num">{{ $invoice->format($invoice->net()) }}</td></tr>
                        <tr><td class="num">{{ $taxName }} {{ $rate }}%</td><td class="num">{{ $invoice->format($invoice->tax) }}</td></tr>
                        <tr><td class="num">Total</td><td class="num">{{ $invoice->format($invoice->total) }}</td></tr>
                    @endif
                @else
                    <tr><td class="num">Total</td><td class="num">{{ $invoice->format($invoice->total) }}</td></tr>
                @endif
                @if ($invoice->unique_code > 0)
                    <tr><td class="num muted">Unique transfer code</td><td class="num muted">{{ $invoice->format($invoice->unique_code) }}</td></tr>
                @endif
                <tr class="total"><td class="num">{{ $invoice->status === InvoiceStatus::Paid ? 'Paid' : 'Amount due' }}</td><td class="num">{{ $invoice->format($invoice->amount_due) }}</td></tr>
            </tfoot>
        </table>

        @if ($invoice->status === InvoiceStatus::Paid)
            <p class="muted" style="margin-top: 16px">Paid on {{ $date($invoice->paid_at) }}. Thank you.</p>
        @elseif ($invoice->isOpen())
            @if ($payOnline)
                <div class="box">
                    <h2>Pay online</h2>
                    <p class="muted">{{ $invoice->automatic ? 'Pay with a card or e-wallet. Later renewals are charged automatically.' : 'Virtual account, QRIS, e-wallet or card.' }}</p>
                    @if ($canPay)
                        <a href="{{ route('billing.invoices.pay', $invoice) }}" class="btn no-print">Pay {{ $invoice->format($invoice->amount_due) }}</a>
                    @endif
                </div>
            @else
                <div class="box">
                    <h2>Pay by bank transfer</h2>
                    <table>
                        <tbody>
                            <tr><th scope="row">Bank</th><td>{{ $bank['bank_name'] ?? '' }}</td></tr>
                            <tr><th scope="row">Account number</th><td>{{ $bank['account_number'] ?? '' }}</td></tr>
                            <tr><th scope="row">Account holder</th><td>{{ $bank['account_holder'] ?? '' }}</td></tr>
                            @if (! empty($bank['swift']))
                                <tr><th scope="row">SWIFT</th><td>{{ $bank['swift'] }}</td></tr>
                            @endif
                            <tr><th scope="row">Amount (exact)</th><td><strong>{{ $invoice->format($invoice->amount_due) }}</strong></td></tr>
                            <tr><th scope="row">Reference</th><td>{{ $invoice->number }}</td></tr>
                        </tbody>
                    </table>
                    <p class="muted">Transfer the exact amount, including the last digits: it is how we match your payment.</p>

                    @if ($invoice->status === InvoiceStatus::Rejected && $invoice->rejected_reason)
                        <p class="status-bad"><strong>We could not confirm your payment:</strong> {{ $invoice->rejected_reason }}</p>
                    @endif

                    @if ($invoice->status === InvoiceStatus::Submitted)
                        <p><strong>Thank you.</strong> We are checking your payment (submitted {{ $date($invoice->submitted_at) }}) and will confirm by email.</p>
                    @elseif ($canPay)
                        <form method="POST" action="{{ route('billing.invoices.paid', $invoice) }}" enctype="multipart/form-data" class="no-print">
                            @csrf
                            <label for="proof">Proof of payment (optional)</label>
                            <input id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" aria-describedby="proof-help">
                            <div id="proof-help" class="muted" style="font-size: 13px">Image or PDF, at most 5 MB. Only you and our billing team can see it.</div>
                            <p><button type="submit" class="btn">I have paid</button></p>
                        </form>
                    @endif
                </div>
            @endif
        @endif

        @if ($invoice->proof_path)
            <p class="muted no-print" style="margin-top: 16px">
                Proof of payment: <a href="{{ route('billing.invoices.proof', $invoice) }}" target="_blank" rel="noopener">{{ $invoice->proof_name ?? 'view' }}</a>
            </p>
        @endif
    </main>
</body>
</html>
