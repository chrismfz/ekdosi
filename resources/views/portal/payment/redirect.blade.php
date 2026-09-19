@php use App\Support\Money; @endphp
<x-portal-layout title="{{ __('portal.payment.redirect_title') }}">
    <div class="mx-auto max-w-md text-center">
        <flux:heading size="xl">{{ __('portal.payment.redirect_title') }}</flux:heading>
        <flux:text class="mt-2 mb-6">
            {{ __('portal.payment.redirect_intro', ['amount' => Money::eur($intent->amount)]) }}
        </flux:text>

        {{-- Auto-submitting signed vPOS form. Values are Blade-escaped (no attribute
             injection); the digest was computed server-side — the shared secret is
             never in this page. --}}
        <form id="vpos-form" method="POST" action="{{ $form->action }}">
            @foreach ($form->fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <flux:button type="submit" variant="primary" class="w-full">{{ __('portal.payment.continue_to_payment') }}</flux:button>
        </form>

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.statement') }}">{{ __('portal.payment.cancel_back') }}</flux:link>
        </flux:text>
    </div>

    <script>
        // Submit as soon as the page is parsed so the customer barely sees this page.
        document.getElementById('vpos-form').submit();
    </script>
</x-portal-layout>
