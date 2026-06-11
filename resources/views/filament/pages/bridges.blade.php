<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Οι πηγές τιμολόγησης (γέφυρες) που τροφοδοτούν τα «Εισερχόμενα». Κάθε γέφυρα στέλνει
        παραστατικά που γίνονται <strong>πρόχειρα</strong> ekdosi και εκδίδονται κανονικά (→ myDATA).
        Σήμερα: <strong>WHMCS</strong>. Νέες γέφυρες (WooCommerce, Blesta…) προστίθενται όταν χρειαστεί.
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($bridges as $b)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-2">
                        <span>{{ $b['label'] }}</span>
                        <x-filament::badge :color="$b['status_color']">{{ $b['status_label'] }}</x-filament::badge>
                    </div>
                </x-slot>

                <div class="space-y-2 text-sm">
                    <div class="text-gray-500 dark:text-gray-400">
                        Μονάδα: <strong>{{ $b['doc_noun'] }}</strong> · αναγνωριστικό: {{ $b['external_id_label'] }}
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($b['write_back'])
                            <x-filament::badge color="info" size="sm">Write-back MARK</x-filament::badge>
                        @endif
                        @if ($b['third_party'])
                            <x-filament::badge color="info" size="sm">Τρίτοι / split</x-filament::badge>
                        @endif
                    </div>

                    @if ($b['settings_url'])
                        <div class="pt-2">
                            <x-filament::button tag="a" :href="$b['settings_url']" size="sm" color="gray" icon="heroicon-o-cog-6-tooth">
                                Ρυθμίσεις {{ $b['label'] }}
                            </x-filament::button>
                        </div>
                    @elseif ($b['status'] === 'unconfigured')
                        <div class="pt-1 text-gray-500 dark:text-gray-400">
                            Η ρύθμιση γίνεται από τον διαχειριστή συστήματος (καρτέλα εταιρείας → {{ $b['label'] }}).
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <div class="text-xs text-gray-400 dark:text-gray-500">
        Σημείωση: η ενεργοποίηση/απενεργοποίηση ανά γέφυρα και τα διαπιστευτήρια ανά σύνδεση
        (πολλαπλές πηγές ίδιου τύπου) ενεργοποιούνται όταν προστεθεί δεύτερη πραγματική γέφυρα.
    </div>
</x-filament-panels::page>
