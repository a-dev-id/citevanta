@if ($bank)
**Pay by bank transfer**

<x-mail::table>
| | |
|:--|:--|
| Bank | {{ $bank['bank_name'] }} |
| Account number | {{ $bank['account_number'] }} |
| Account holder | {{ $bank['account_holder'] }} |
@if (! empty($bank['swift']))
| SWIFT | {{ $bank['swift'] }} |
@endif
| Amount (exact) | **{{ $amount }}** |
| Reference | {{ $invoice->number }} |
</x-mail::table>

Please transfer the exact amount, including the last digits: it is how we match your payment. Then open the invoice and click "I have paid".
@endif
