<x-layouts::app title="Billing" :breadcrumbs="['Admin' => null, 'Billing' => null]">
    <x-flash />
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <p class="text-muted small">
        Provider: <strong>{{ $provider }}</strong>@if ($provider === 'xendit') ({{ $xenditMode }} mode)@endif.
        Check the bank statement for the exact amount (it includes the unique code), then mark the invoice as paid: the subscription starts or renews at once.
    </p>

    <x-card title="Waiting for payment" subtitle="Submitted payments first" :flush="true" :collapsible="false">
        @if ($open->isEmpty())
            <x-empty-state icon="fa-file-invoice-dollar" text="No open invoices." />
        @else
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Invoice</th><th scope="col">Team</th><th scope="col" class="cv-num">Amount</th>
                            <th scope="col">Due</th><th scope="col">Status</th><th scope="col">Proof</th><th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($open as $invoice)
                            <tr>
                                <td>
                                    <a href="{{ route('billing.invoices.show', $invoice) }}" target="_blank" rel="noopener">{{ $invoice->number }}</a>
                                    <span class="d-block small text-muted">{{ $invoice->description }}</span>
                                </td>
                                <td>{{ $invoice->team->name }}</td>
                                <td class="cv-num fw-semibold">{{ $invoice->format($invoice->amount_due) }}</td>
                                <td>{{ $invoice->due_at->format('j M Y') }}</td>
                                <td><x-badge-status :label="$invoice->isOverdue() ? 'Overdue' : $invoice->status->label()" :variant="$invoice->isOverdue() ? 'danger' : $invoice->status->variant()" /></td>
                                <td>
                                    @if ($invoice->proof_path)
                                        <a href="{{ route('billing.invoices.proof', $invoice) }}" target="_blank" rel="noopener">View</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td style="min-width: 280px">
                                    <form method="POST" action="{{ route('admin.billing.paid', $invoice) }}" class="d-flex flex-wrap gap-1 mb-1">
                                        @csrf
                                        <label class="visually-hidden" for="paid-on-{{ $invoice->id }}">Payment date</label>
                                        <input id="paid-on-{{ $invoice->id }}" type="date" name="paid_on" value="{{ now($invoice->team->timezoneName())->toDateString() }}" class="form-control form-control-sm" style="width: 9.5rem" required>
                                        <label class="visually-hidden" for="note-{{ $invoice->id }}">Note</label>
                                        <input id="note-{{ $invoice->id }}" type="text" name="note" placeholder="Note (optional)" class="form-control form-control-sm" style="width: 9rem">
                                        <button type="submit" class="btn btn-sm btn-success">Mark as paid</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.billing.reject', $invoice) }}" class="d-flex flex-wrap gap-1">
                                        @csrf
                                        <label class="visually-hidden" for="reason-{{ $invoice->id }}">Reason</label>
                                        <input id="reason-{{ $invoice->id }}" type="text" name="reason" placeholder="Reason for the owner" class="form-control form-control-sm" style="width: 14rem" required>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <x-card title="Recently paid or void" :flush="true" :collapsible="false">
        @if ($recent->isEmpty())
            <x-empty-state icon="fa-receipt" text="Nothing yet." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr><th scope="col">Invoice</th><th scope="col">Team</th><th scope="col" class="cv-num">Amount</th><th scope="col">Status</th><th scope="col">Paid</th><th scope="col">Note</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $invoice)
                            <tr>
                                <td><a href="{{ route('billing.invoices.show', $invoice) }}" target="_blank" rel="noopener">{{ $invoice->number }}</a></td>
                                <td>{{ $invoice->team->name }}</td>
                                <td class="cv-num">{{ $invoice->format($invoice->amount_due) }}</td>
                                <td><x-badge-status :label="$invoice->status->label()" :variant="$invoice->status->variant()" /></td>
                                <td>{{ $invoice->paid_at?->format('j M Y') ?? '—' }}@if ($invoice->markedPaidBy) <span class="d-block small text-muted">by {{ $invoice->markedPaidBy->name }}</span>@endif</td>
                                <td class="small">{{ $invoice->payment_note ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts::app>
