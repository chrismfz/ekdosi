<x-portal-layout title="{{ __('portal.password.title') }}">
    <div class="mx-auto max-w-sm">
        <flux:heading size="xl">{{ __('portal.password.forgot_heading') }}</flux:heading>
        <flux:text class="mt-2 mb-6">
            {{ __('portal.password.forgot_intro') }}
        </flux:text>

        <form method="POST" action="{{ route('portal.password.email') }}" class="flex flex-col gap-5">
            @csrf
            <flux:input
                name="email"
                type="email"
                label="{{ __('portal.common.email') }}"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
            />

            {{-- Honeypot: hidden from humans, tempting to bots. Server bails if filled.
                 Named `fax` (not a website/email/name field) so autofill leaves it empty. --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;" tabindex="-1">
                <label>{{ __('portal.password.honeypot') }}
                    <input type="text" name="fax" tabindex="-1" autocomplete="off" value="">
                </label>
            </div>

            <flux:button type="submit" variant="primary" class="w-full">{{ __('portal.password.send_link') }}</flux:button>
        </form>

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.login') }}">{{ __('portal.password.back_to_login') }}</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
