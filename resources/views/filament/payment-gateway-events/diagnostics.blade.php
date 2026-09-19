{{--
    «Γιατί» μιας εγγραφής στο «Log πύλης».

    Ο operator δεν έχει SSH· ό,τι χρειάζεται για να διαγνώσει μια απορριφθείσα
    είσπραξη πρέπει να φαίνεται εδώ. Δείχνει ΜΟΝΟ ονόματα πεδίων — ποτέ τιμές,
    που μεταφέρουν δεδομένα παραγγελίας του πελάτη.

    Σημ.: στο panel δεν υπάρχει Tailwind utility layer· κάθε class εδώ είναι
    χειρο-ορισμένη στο resources/css/panel.css (βλ. CLAUDE.md).
--}}
@php
    $d = $record->diagnostics ?? [];
    $code = $d['code'] ?? null;
    $posted = $d['posted_order'] ?? [];
    $expected = $d['expected_order'] ?? [];
    $unknown = $d['unknown_fields'] ?? [];
    $orderDiffers = $posted !== [] && $expected !== []
        && array_values(array_intersect($expected, $posted)) !== array_values($posted);
@endphp

<div class="space-y-3 text-sm">
    @if ($code === null)
        <div class="text-gray-500 dark:text-gray-400">
            Δεν καταγράφηκαν λεπτομέρειες διάγνωσης για αυτή την εγγραφή.
            {{-- Rows written before this column existed, or paths with nothing to add. --}}
        </div>
    @else
        <div>
            @switch ($code)
                @case ('ok')
                    <x-filament::badge color="success">Η υπογραφή επαληθεύτηκε</x-filament::badge>
                    <div class="text-gray-500 dark:text-gray-400">
                        Η τράπεζα έστειλε ακριβώς τα πεδία που περιμέναμε.
                        @unless ($orderDiffers)
                            Η λίστα <span class="font-mono text-xs">RETURN_FIELD_ORDER</span> επιβεβαιώνεται από αυτή τη συναλλαγή.
                        @endunless
                    </div>
                    @break

                @case ('unknown_fields')
                    <x-filament::badge color="danger">Άγνωστο πεδίο στην απάντηση</x-filament::badge>
                    <div class="text-gray-500 dark:text-gray-400">
                        Η τράπεζα έστειλε πεδίο που δεν αναγνωρίζουμε, οπότε η είσπραξη <strong>δεν καταχωρίστηκε</strong>.
                        Πρόσθεσε το παρακάτω πεδίο στη λίστα αναμενόμενων πεδίων, στη θέση που δείχνει η σειρά της τράπεζας.
                    </div>
                    @break

                @case ('order_mismatch')
                    <x-filament::badge color="warning">Λάθος σειρά πεδίων (το μυστικό είναι σωστό)</x-filament::badge>
                    <div class="text-gray-500 dark:text-gray-400">
                        Το shared secret επαληθεύτηκε — μόνο η <strong>σειρά</strong> που περιμέναμε διαφέρει.
                        Η «Σειρά τράπεζας» παρακάτω είναι η σωστή λίστα.
                    </div>
                    @break

                @case ('secret_or_payload_mismatch')
                    <x-filament::badge color="danger">Η υπογραφή δεν ταιριάζει με καμία σειρά</x-filament::badge>
                    <div class="text-gray-500 dark:text-gray-400">
                        Δεν είναι θέμα σειράς πεδίων. Έλεγξε πρώτα το <strong>Shared Secret</strong> του τερματικού
                        — ή πρόκειται για πλαστή απάντηση.
                    </div>
                    @break

                @case ('no_digest')
                    <x-filament::badge color="danger">Η απάντηση δεν είχε υπογραφή</x-filament::badge>
                    <div class="text-gray-500 dark:text-gray-400">Καμία επαλήθευση δεν ήταν δυνατή.</div>
                    @break

                @case ('intent_not_found')
                    <x-filament::badge color="gray">Άγνωστη παραγγελία</x-filament::badge>
                    <div class="text-gray-500 dark:text-gray-400">
                        Το <span class="font-mono text-xs">orderid</span> δεν αντιστοιχεί σε καμία παραγγελία πληρωμής.
                    </div>
                    @break
            @endswitch
        </div>

        @if ($unknown !== [])
            <div>
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Άγνωστα πεδία</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($unknown as $f)
                        <x-filament::badge color="danger" size="sm">{{ $f }}</x-filament::badge>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($posted !== [])
            <div>
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Σειρά τράπεζας</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($posted as $f)
                        <x-filament::badge :color="in_array($f, $unknown, true) ? 'danger' : 'gray'" size="sm">
                            {{ $f }}
                        </x-filament::badge>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($expected !== [])
            <div>
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Σειρά που περιμέναμε</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($expected as $f)
                        <x-filament::badge :color="in_array($f, $posted, true) || $posted === [] ? 'gray' : 'warning'" size="sm">
                            {{ $f }}
                        </x-filament::badge>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    @if (filled($record->message))
        <div class="border-t border-gray-100 dark:border-gray-700">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Μήνυμα παρόχου</div>
            <div class="font-mono text-xs">{{ $record->message }}</div>
        </div>
    @endif
</div>
