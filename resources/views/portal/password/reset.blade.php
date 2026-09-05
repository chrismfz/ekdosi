<x-portal-layout title="Ορισμός κωδικού">
    <div class="mx-auto max-w-sm">
        <flux:heading size="xl">Όρισε νέο κωδικό</flux:heading>
        <flux:text class="mt-2 mb-6">Διάλεξε έναν κωδικό για τον λογαριασμό σου.</flux:text>

        <form method="POST" action="{{ route('portal.password.update') }}" class="flex flex-col gap-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <flux:input
                name="email"
                type="email"
                label="Email"
                value="{{ old('email', $email) }}"
                autocomplete="username"
                required
                readonly
            />
            <flux:input
                name="password"
                type="password"
                label="Νέος κωδικός"
                autocomplete="new-password"
                required
                autofocus
            />
            <flux:input
                name="password_confirmation"
                type="password"
                label="Επιβεβαίωση κωδικού"
                autocomplete="new-password"
                required
            />

            <flux:button type="submit" variant="primary" class="w-full">Αποθήκευση</flux:button>
        </form>
    </div>
</x-portal-layout>
