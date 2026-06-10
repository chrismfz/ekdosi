<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Καθολικές ρυθμίσεις (deploy-wide). Η ρύθμιση εδώ υπερισχύει του env (env = προεπιλογή)·
        αποθηκεύονται μόνο οι αποκλίσεις, με audit. Για τον χρονοπρογραμματιστή δες
        «Ρυθμίσεις χρονοπρογραμματιστή».
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Αποθήκευση
            </x-filament::button>
        </div>
    </form>

    {{-- Read-only posture --}}
    @php($i = $info ?? [])
    <x-filament::section>
        <x-slot name="heading">Κρυπτογράφηση μυστικών (at-rest)</x-slot>
        <div class="space-y-2 text-sm">
            <div class="flex items-center gap-2">
                <x-filament::badge :color="($i['encrypt_at_rest'] ?? false) ? 'info' : 'gray'">
                    {{ ($i['encrypt_at_rest'] ?? false) ? 'Κρυπτογραφημένα (APP_KEY)' : 'Plaintext (DR-ready)' }}
                </x-filament::badge>
            </div>
            <p class="text-gray-500 dark:text-gray-400">
                Read-only εδώ <strong>επίτηδες</strong>: η αλλαγή χρειάζεται επανακρυπτογράφηση των
                υπαρχουσών εγγραφών — αλλιώς μένει «μικτή» κατάσταση. Άλλαξέ την με ασφάλεια από CLI:
            </p>
            <pre class="rounded bg-gray-100 p-2 text-xs dark:bg-gray-800"><code>php artisan secrets:reencrypt --to=plain        # → plaintext (DR default)
php artisan secrets:reencrypt --to=encrypted    # → encrypted under APP_KEY</code></pre>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Email (κατάσταση)</x-slot>
        <div class="space-y-1 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span>Καθολικός mailer:</span>
                <x-filament::badge :color="($i['global_mailer_sends'] ?? false) ? 'success' : 'warning'">
                    {{ $i['global_mailer'] ?? '—' }}@if(!empty($i['global_mailer_host'])) · {{ $i['global_mailer_host'] }}@endif
                </x-filament::badge>
                @unless($i['global_mailer_sends'] ?? false)
                    <span class="text-warning-600 dark:text-warning-400">δεν στέλνει πραγματικά (log/array)</span>
                @endunless
            </div>
            <div>Εταιρίες με δικό τους SMTP: <strong>{{ $i['tenants_with_smtp'] ?? 0 }}</strong></div>
            <p class="text-gray-500 dark:text-gray-400">
                Οι per-tenant ρυθμίσεις SMTP + «Δοκιμή SMTP» ανά εταιρία είναι στην καρτέλα της εταιρίας.
                Αποτυχίες αποστολής: δες «Υγεία συστήματος».
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
