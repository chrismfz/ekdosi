<x-portal-layout title="Ορισμός κωδικού">
    <div class="mx-auto max-w-sm">
        <flux:heading size="xl">Ξέχασες τον κωδικό;</flux:heading>
        <flux:text class="mt-2 mb-6">
            Δώσε το email σου και θα λάβεις σύνδεσμο για να ορίσεις κωδικό.
            Αν είσαι νέος χρήστης που μόλις προσκλήθηκε, από εδώ ορίζεις τον πρώτο σου κωδικό.
        </flux:text>

        <form method="POST" action="{{ route('portal.password.email') }}" class="flex flex-col gap-5">
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

            {{-- Honeypot: hidden from humans, tempting to bots. Server bails if filled.
                 Named `fax` (not a website/email/name field) so autofill leaves it empty. --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;" tabindex="-1">
                <label>Μην το συμπληρώσεις
                    <input type="text" name="fax" tabindex="-1" autocomplete="off" value="">
                </label>
            </div>

            <flux:button type="submit" variant="primary" class="w-full">Αποστολή συνδέσμου</flux:button>
        </form>

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.login') }}">Επιστροφή στην είσοδο</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
