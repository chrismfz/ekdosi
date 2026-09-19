<x-portal-layout title="{{ __('portal.password.title') }}">
    <div class="mx-auto max-w-sm">
        <flux:heading size="xl">{{ __('portal.password.reset_heading') }}</flux:heading>
        <flux:text class="mt-2 mb-6">{{ __('portal.password.reset_intro') }}</flux:text>

        <form method="POST" action="{{ route('portal.password.update') }}" class="flex flex-col gap-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <flux:input
                name="email"
                type="email"
                label="{{ __('portal.common.email') }}"
                value="{{ old('email', $email) }}"
                autocomplete="username"
                required
                readonly
            />
            <flux:input
                name="password"
                type="password"
                label="{{ __('portal.common.new_password') }}"
                autocomplete="new-password"
                required
                autofocus
            />
            <flux:input
                name="password_confirmation"
                type="password"
                label="{{ __('portal.password.confirm_password') }}"
                autocomplete="new-password"
                required
            />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('portal.common.save') }}</flux:button>
        </form>
    </div>
</x-portal-layout>
