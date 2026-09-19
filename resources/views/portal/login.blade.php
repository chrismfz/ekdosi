<x-portal-layout title="{{ __('portal.login.title') }}">
    <div class="mx-auto max-w-sm">
        {{-- #1c branding: on a custom portal host the tenant name heads the page. --}}
        @if ($portalCompany ?? null)
            <flux:text class="mb-1 font-semibold text-zinc-500 dark:text-zinc-400">{{ $portalCompany->name }}</flux:text>
        @endif
        <flux:heading size="xl">{{ __('portal.login.title') }}</flux:heading>
        <flux:text class="mt-2 mb-6">{{ __('portal.login.subtitle') }}</flux:text>

        <form method="POST" action="{{ route('portal.login.attempt') }}" class="flex flex-col gap-5">
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
            <flux:input
                name="password"
                type="password"
                label="{{ __('portal.login.password') }}"
                autocomplete="current-password"
                required
            />
            <div class="flex items-center justify-between">
                <flux:checkbox name="remember" value="1" label="{{ __('portal.login.remember') }}" />
                <flux:link href="{{ route('portal.password.request') }}" class="text-sm">{{ __('portal.login.forgot') }}</flux:link>
            </div>
            <flux:button type="submit" variant="primary" class="w-full">{{ __('portal.login.submit') }}</flux:button>
        </form>
    </div>
</x-portal-layout>
