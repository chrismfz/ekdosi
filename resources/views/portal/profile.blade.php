<x-portal-layout title="Τα στοιχεία μου">
    <flux:heading size="xl">Τα στοιχεία μου</flux:heading>
    <flux:text class="mt-2 mb-6">Διαχειρίσου τον λογαριασμό και τον κωδικό σου.</flux:text>

    <div class="grid gap-6 md:grid-cols-2">
        {{-- Account details --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">Στοιχεία λογαριασμού</flux:heading>
            <form method="POST" action="{{ route('portal.profile.update') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <flux:input name="name" label="Όνομα" value="{{ old('name', $user->name) }}" required />
                <flux:input
                    type="email"
                    label="Email"
                    value="{{ $user->email }}"
                    readonly
                    description="Το email είναι το αναγνωριστικό εισόδου — δεν αλλάζει από εδώ."
                />
                <flux:input name="phone" label="Τηλέφωνο" value="{{ old('phone', $user->phone) }}" />
                <flux:select name="locale" label="Γλώσσα">
                    <flux:select.option value="el" :selected="old('locale', $user->locale) === 'el'">Ελληνικά</flux:select.option>
                    <flux:select.option value="en" :selected="old('locale', $user->locale) === 'en'">English</flux:select.option>
                </flux:select>
                <flux:button type="submit" variant="primary">Αποθήκευση</flux:button>
            </form>
        </div>

        {{-- Change password --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">Αλλαγή κωδικού</flux:heading>
            <form method="POST" action="{{ route('portal.profile.password') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <flux:input type="password" name="current_password" label="Τρέχων κωδικός" autocomplete="current-password" required />
                <flux:input type="password" name="password" label="Νέος κωδικός" autocomplete="new-password" required />
                <flux:input type="password" name="password_confirmation" label="Επιβεβαίωση νέου κωδικού" autocomplete="new-password" required />
                <flux:button type="submit" variant="primary">Αλλαγή κωδικού</flux:button>
            </form>
        </div>
    </div>
</x-portal-layout>
