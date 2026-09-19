<x-portal-layout title="{{ __('portal.profile.title') }}">
    <flux:heading size="xl">{{ __('portal.profile.title') }}</flux:heading>
    <flux:text class="mt-2 mb-6">{{ __('portal.profile.subtitle') }}</flux:text>

    <div class="grid gap-6 md:grid-cols-2">
        {{-- Account details --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">{{ __('portal.profile.account_details') }}</flux:heading>
            <form method="POST" action="{{ route('portal.profile.update') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <flux:input name="name" label="{{ __('portal.profile.name') }}" value="{{ old('name', $user->name) }}" required />
                <flux:input
                    type="email"
                    label="{{ __('portal.common.email') }}"
                    value="{{ $user->email }}"
                    readonly
                    description="{{ __('portal.profile.email_hint') }}"
                />
                <flux:input name="phone" label="{{ __('portal.profile.phone') }}" value="{{ old('phone', $user->phone) }}" />
                <flux:select name="locale" label="{{ __('portal.profile.language') }}">
                    <flux:select.option value="el" :selected="old('locale', $user->locale) === 'el'">{{ __('portal.profile.lang_el') }}</flux:select.option>
                    <flux:select.option value="en" :selected="old('locale', $user->locale) === 'en'">{{ __('portal.profile.lang_en') }}</flux:select.option>
                </flux:select>
                <flux:button type="submit" variant="primary">{{ __('portal.common.save') }}</flux:button>
            </form>
        </div>

        {{-- Change password --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">{{ __('portal.profile.change_password') }}</flux:heading>
            <form method="POST" action="{{ route('portal.profile.password') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <flux:input type="password" name="current_password" label="{{ __('portal.profile.current_password') }}" autocomplete="current-password" required />
                <flux:input type="password" name="password" label="{{ __('portal.common.new_password') }}" autocomplete="new-password" required />
                <flux:input type="password" name="password_confirmation" label="{{ __('portal.profile.confirm_new_password') }}" autocomplete="new-password" required />
                <flux:button type="submit" variant="primary">{{ __('portal.profile.change_password') }}</flux:button>
            </form>
        </div>
    </div>
</x-portal-layout>
