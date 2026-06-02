<x-filament-panels::page>
    @php
        $money = fn ($v) => $v === null ? '—' : '€ ' . number_format((float) $v, 2, ',', '.');
        $vatRates = \App\Support\MyData\Codes::VAT_CATEGORY_RATES;
        $vatLabel = function (array $line) use ($vatRates) {
            // Local lines carry the rate; AADE lines carry the §8.2 category.
            if (($line['vatPercent'] ?? null) !== null) {
                return rtrim(rtrim(number_format((float) $line['vatPercent'], 2, ',', '.'), '0'), ',') . '%';
            }
            $cat = $line['vatCategory'] ?? null;
            if ($cat === null) {
                return '—';
            }
            $rate = $vatRates[$cat] ?? null;
            return $rate !== null
                ? rtrim(rtrim(number_format((float) $rate, 2, ',', '.'), '0'), ',') . '%'
                : 'Κατ. ' . $cat;
        };
    @endphp

    @if ($error)
        <x-filament::section>
            <div class="flex items-center gap-2 text-danger-600 dark:text-danger-400 font-medium">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-6 w-6" />
                <span>{{ $error }}</span>
            </div>
        </x-filament::section>
    @endif

    @if ($doc)
        {{-- Origin banner --}}
        @if ($isOrphan)
            <x-filament::section>
                <div class="flex items-start gap-2 text-sm text-warning-700 dark:text-warning-400">
                    <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="mt-0.5 h-5 w-5" />
                    <span>
                        <strong>Αδέσποτο παραστατικό</strong> — υπάρχει στο myDATA για το ΑΦΜ μας αλλά
                        δεν έχει τοπική εγγραφή στο ekdosi. Τα στοιχεία προέρχονται απευθείας από το myDATA.
                    </span>
                </div>
            </x-filament::section>
        @endif

        {{-- Header / document identity --}}
        <x-filament::section>
            <x-slot name="heading">Στοιχεία παραστατικού</x-slot>

            <div class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-3">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Τύπος</div>
                    <div class="font-medium">
                        {{ $doc['invoiceType'] ?? '—' }}
                        @if ($doc['invoiceTypeLabel'] ?? null)
                            <span class="text-gray-500 dark:text-gray-400">— {{ $doc['invoiceTypeLabel'] }}</span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Κωδικός (Σειρά / ΑΑ)</div>
                    <div class="font-medium">{{ $doc['invcode'] ?? '—' }}</div>
                </div>

                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Έκδοση</div>
                    <div class="font-medium">{{ $doc['issuedAtHuman'] ?? ($doc['issueDate'] ?? '—') }}</div>
                </div>

                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Κατάσταση myDATA</div>
                    <div>
                        <x-filament::badge :color="($doc['state'] ?? null) === 'CANCELLED' ? 'danger' : 'success'">
                            {{ $doc['state'] ?? '—' }}
                        </x-filament::badge>
                        @if ($doc['localStatus'] ?? null)
                            <span class="text-xs text-gray-500 dark:text-gray-400">(τοπικά: {{ $doc['localStatus'] }})</span>
                        @endif
                    </div>
                </div>

                <div class="md:col-span-2">
                    <div class="text-sm text-gray-500 dark:text-gray-400">ΜΑΡΚ</div>
                    <div class="font-mono text-sm">{{ $doc['mark'] }}</div>
                </div>

                @if ($doc['uid'] ?? null)
                    <div class="md:col-span-3">
                        <div class="text-sm text-gray-500 dark:text-gray-400">UID</div>
                        <div class="font-mono text-xs break-all">{{ $doc['uid'] }}</div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- AADE QR code (from qrCodeUrl). For local invoices this is the stored
             mydata_url; for imports it appears after «Άντληση/έλεγχος από ΑΑΔΕ».
             Only render for a real http(s) URL (mydata_url is operator/ETL-
             writable → never emit a javascript:/data: scheme as a clickable
             href); the QR image is rendered guarded (no 500 if endroid throws). --}}
        @php
            $qrUrl = $doc['qrCodeUrl'] ?? null;
            $qrSafe = is_string($qrUrl) && \Illuminate\Support\Str::startsWith($qrUrl, ['http://', 'https://']);
            $qrImg = $qrSafe ? \App\Support\MyData\QrImage::tryDataUri($qrUrl) : null;
        @endphp
        @if ($qrSafe)
            <x-filament::section>
                <x-slot name="heading">QR ΑΑΔΕ</x-slot>
                <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-center">
                    @if ($qrImg)
                        <img src="{{ $qrImg }}" alt="QR ΑΑΔΕ" class="h-40 w-40" />
                    @else
                        <div class="flex h-40 w-40 items-center justify-center rounded border border-dashed border-gray-300 text-xs text-gray-400 dark:border-gray-600">
                            QR μη διαθέσιμο
                        </div>
                    @endif
                    <div class="min-w-0 text-sm">
                        <div class="text-gray-500 dark:text-gray-400">Σύνδεσμος επισκόπησης ΑΑΔΕ</div>
                        <a href="{{ $qrUrl }}" target="_blank" rel="noopener"
                           class="font-mono text-xs break-all text-primary-600 hover:underline dark:text-primary-400">
                            {{ $qrUrl }}
                        </a>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- Cross-check result (popup-panel) from «Άντληση/έλεγχος από ΑΑΔΕ»:
             what AGREES (✓) and what DIFFERS (⚠) — read-only, nothing is
             auto-overwritten on a filed document. --}}
        @if ($enrichReport)
            @php
                $cmp = $enrichReport['comparison'] ?? [];
                $diffCount = collect($cmp)->where('match', false)->count();
            @endphp
            <x-filament::section>
                <x-slot name="heading">Σύγκριση με ΑΑΔΕ</x-slot>
                <x-slot name="description">
                    {{ $diffCount === 0 ? 'Όλα τα πεδία συμφωνούν με το ΑΑΔΕ.' : $diffCount . ' διαφορά/ές — δείτε παρακάτω (δεν αντικαθίστανται αυτόματα).' }}
                </x-slot>

                @if (($enrichReport['stamped_qr'] ?? false) || ($enrichReport['filled'] ?? []) !== [])
                    <div class="mb-3 text-sm text-success-700 dark:text-success-400">
                        @if ($enrichReport['stamped_qr'] ?? false) <div>✓ Συμπληρώθηκε το QR.</div> @endif
                        @if (($enrichReport['filled'] ?? []) !== [])
                            <div>✓ Συμπληρώθηκαν: {{ implode(', ', $enrichReport['filled']) }}.</div>
                        @endif
                    </div>
                @endif

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-4"></th>
                            <th class="py-1 pr-4">Πεδίο</th>
                            <th class="py-1 pr-4">Τοπικά</th>
                            <th class="py-1">ΑΑΔΕ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cmp as $row)
                            <tr @class(['border-t border-gray-100 dark:border-white/5', 'text-danger-700 dark:text-danger-400' => ! $row['match']])>
                                <td class="py-1 pr-4">
                                    @if ($row['match'])
                                        <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4 text-success-600" />
                                    @else
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-danger-600" />
                                    @endif
                                </td>
                                <td class="py-1 pr-4">{{ $row['label'] }}</td>
                                <td class="py-1 pr-4 font-mono">{{ $row['local'] ?? '—' }}</td>
                                <td class="py-1 font-mono">{{ $row['aade'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        {{-- Parties --}}
        <x-filament::section>
            <x-slot name="heading">Συναλλασσόμενοι</x-slot>

            @php
                // Direction-aware labels. For an INBOUND (expense) doc the
                // issuer is the SUPPLIER, not us — labelling it "Εκδότης (εμείς)"
                // is misleading. myDATA also omits the issuer entirely for retail
                // ΑΛΠ (13.1), so make the "who sold to us" gap explicit.
                $inbound = ($doc['direction'] ?? null) === 'inbound';
                $issuerLabel = $inbound ? 'Εκδότης (προμηθευτής)' : 'Εκδότης (εμείς)';
                $counterLabel = $inbound ? 'Λήπτης (εμείς)' : 'Προς (πελάτης)';
                $hasIssuer = ($doc['issuerName'] ?? null) || ($doc['issuerVat'] ?? null);
            @endphp
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $issuerLabel }}</div>
                    @if ($hasIssuer)
                        <div class="font-medium">{{ $doc['issuerName'] ?? '—' }}</div>
                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">
                            ΑΦΜ {{ $doc['issuerVat'] ?? '—' }}
                        </div>
                    @elseif ($inbound)
                        <div class="text-gray-500 dark:text-gray-400 italic">δεν δηλώνεται στο myDATA (λιανική συναλλαγή)</div>
                    @else
                        <div class="font-medium">—</div>
                    @endif
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $counterLabel }}</div>
                    <div class="font-medium">{{ $doc['counterpartName'] ?? '— (λιανική / ιδιώτης)' }}</div>
                    <div class="font-mono text-xs text-gray-500 dark:text-gray-400">
                        ΑΦΜ {{ $doc['counterpartVat'] ?? '—' }}
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Lines --}}
        <x-filament::section>
            <x-slot name="heading">
                <span class="flex items-center gap-2">
                    Γραμμές
                    <x-filament::badge>{{ count($doc['lines']) }}</x-filament::badge>
                </span>
            </x-slot>

            @if (count($doc['lines']) === 0)
                <div class="text-sm text-gray-500 dark:text-gray-400">Δεν υπάρχουν αναλυτικές γραμμές.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pr-4">#</th>
                                <th class="py-2 pr-4">Περιγραφή</th>
                                <th class="py-2 pr-4 text-right">Ποσότητα</th>
                                <th class="py-2 pr-4 text-right">Καθαρή αξία</th>
                                <th class="py-2 pr-4 text-right">ΦΠΑ</th>
                                <th class="py-2 pr-4 text-right">Αξία ΦΠΑ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($doc['lines'] as $line)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4">{{ $line['lineNumber'] ?? $loop->iteration }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($line['itemDescr'] ?? null)
                                            {{ $line['itemDescr'] }}
                                            @if ($line['itemCode'] ?? null)
                                                <span class="text-xs text-gray-400">({{ $line['itemCode'] }})</span>
                                            @endif
                                        @elseif (! empty($line['classifications']))
                                            {{-- myDATA carries no free-text description for these docs;
                                                 the E3 classification is the "what is this" signal. --}}
                                            @foreach ($line['classifications'] as $cls)
                                                <div>
                                                    <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $cls['type'] }}</span>
                                                    @if ($cls['typeLabel'])
                                                        — {{ $cls['typeLabel'] }}
                                                    @endif
                                                    @if ($cls['categoryLabel'])
                                                        <span class="text-xs text-gray-400">({{ $cls['categoryLabel'] }})</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right whitespace-nowrap">
                                        @if (($line['quantity'] ?? null) !== null)
                                            {{ rtrim(rtrim(number_format((float) $line['quantity'], 3, ',', '.'), '0'), ',') }}
                                            @if ($line['unit'] ?? null)
                                                <span class="text-xs text-gray-400">{{ $line['unit'] }}</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($line['netValue'] ?? null) }}</td>
                                    <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $vatLabel($line) }}</td>
                                    <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($line['vatAmount'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Totals --}}
        <x-filament::section>
            <x-slot name="heading">Σύνολα</x-slot>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Καθαρή αξία</div>
                    <div class="text-lg font-semibold">{{ $money($doc['netTotal'] ?? null) }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ΦΠΑ</div>
                    <div class="text-lg font-semibold">{{ $money($doc['vatTotal'] ?? null) }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Σύνολο ({{ $doc['currency'] ?? 'EUR' }})</div>
                    <div class="text-lg font-bold">{{ $money($doc['grossTotal'] ?? null) }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Raw myDATA XML (audit) --}}
        @php
            $requestXml = $doc['requestXml'] ?? null;
            $responseXml = $doc['responseXml'] ?? null;
        @endphp
        @if ($requestXml || $responseXml)
            <x-filament::section :collapsible="true" :collapsed="true">
                <x-slot name="heading">
                    <span class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-code-bracket" class="h-5 w-5 text-gray-400" />
                        myDATA XML (ακατέργαστο)
                    </span>
                </x-slot>
                <x-slot name="description">
                    Διατηρείται αυτούσιο για νομικό έλεγχο. {{ $isOrphan ? 'Απόκριση από myDATA.' : 'Από τη βάση (mydata_marks).' }}
                </x-slot>

                <div class="space-y-4">
                    @if ($requestXml)
                        <div>
                            <div class="mb-1 text-sm font-medium text-gray-600 dark:text-gray-300">Request</div>
                            <textarea readonly rows="12"
                                class="w-full rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-2 font-mono text-xs">{{ $requestXml }}</textarea>
                        </div>
                    @endif
                    @if ($responseXml)
                        <div>
                            <div class="mb-1 text-sm font-medium text-gray-600 dark:text-gray-300">Response</div>
                            <textarea readonly rows="12"
                                class="w-full rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-2 font-mono text-xs">{{ $responseXml }}</textarea>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
