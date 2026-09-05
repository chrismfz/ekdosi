<x-portal-layout title="Είσοδος πελατών">
    <div class="mx-auto max-w-sm">
        <flux:heading size="xl">Είσοδος πελατών</flux:heading>
        <flux:text class="mt-2 mb-6">Δες τα παραστατικά και τα στοιχεία σου.</flux:text>

        <form method="POST" action="{{ route('portal.login.attempt') }}" class="flex flex-col gap-5">
            @csrf
            <flux:input
                name="email"
                type="email"
                label="Email"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
            />
            <flux:input
                name="password"
                type="password"
                label="Κωδικός"
                autocomplete="current-password"
                required
            />
            <flux:checkbox name="remember" value="1" label="Να με θυμάσαι" />
            <flux:button type="submit" variant="primary" class="w-full">Είσοδος</flux:button>
        </form>
    </div>
</x-portal-layout>
