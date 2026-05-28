@component('mail::message')
# Καρτέλα πελάτη

Αγαπητέ/ή {{ $customer->name }},

@if ($bodyMessage)
{{ $bodyMessage }}
@else
Επισυνάπτεται η καρτέλα κινήσεων του λογαριασμού σας σε μορφή PDF.
@endif

@if ($tenant)
Με εκτίμηση,
{{ $tenant->name }}
@endif
@endcomponent
