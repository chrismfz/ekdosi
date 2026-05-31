@component('mail::message')
# Προσφορά {{ $quote->code }}

Αξιότιμε/η πελάτη,

Σας αποστέλλουμε συνημμένα την προσφορά μας{{ $quote->subject ? ' για: '.$quote->subject : '' }}.

@if ($quote->valid_until)
**Ισχύει έως:** {{ $quote->valid_until->format('d/m/Y') }}
@endif

**Συνολική αξία:** {{ number_format((float) $quote->gross_total, 2, ',', '.') }} €

@if ($quote->customer_notes)
{{ $quote->customer_notes }}
@endif

Παραμένουμε στη διάθεσή σας για οποιαδήποτε διευκρίνιση.

Με εκτίμηση,
{{ $tenant->name }}

<small>Η παρούσα προσφορά δεν αποτελεί φορολογικό παραστατικό.</small>
@endcomponent
