<x-portal-layout title="Ο λογαριασμός μου">
    <flux:heading size="xl">Καλωσήρθες, {{ $user->name }}</flux:heading>
    <flux:text class="mt-2">Είσαι συνδεδεμένος ως <strong>{{ $user->email }}</strong>.</flux:text>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg">Τα παραστατικά μου</flux:heading>
        <flux:text class="mt-1">
            Σύντομα εδώ θα βλέπεις τα παραστατικά και τις υπηρεσίες σου.
        </flux:text>
        <flux:badge class="mt-4" color="zinc">Υπό κατασκευή</flux:badge>
    </div>
</x-portal-layout>
