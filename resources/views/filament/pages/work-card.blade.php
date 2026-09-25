<x-filament-panels::page>
    <style>
        .wc-wrap { display:flex; flex-direction:column; align-items:center; gap:1rem; padding:1rem 0; text-align:center; }
        .wc-clock { font-size:2.5rem; font-weight:700; font-variant-numeric:tabular-nums; }
        .wc-name { font-size:1.1rem; font-weight:600; }
        .wc-note { font-size:.85rem; color:#6b7280; max-width:32rem; } .dark .wc-note { color:#9ca3af; }
        .wc-badge { display:inline-block; font-size:.75rem; padding:.15rem .5rem; border-radius:999px; background:#dcfce7; color:#166534; } .dark .wc-badge { background:rgba(74,222,128,.2); color:#bbf7d0; }
        .wc-warn { background:#fef3c7; color:#92400e; } .dark .wc-warn { background:rgba(251,191,36,.2); color:#fde68a; }
        .wc-list { width:100%; max-width:32rem; border-collapse:collapse; font-size:.9rem; }
        .wc-list td { padding:.4rem .5rem; border-bottom:1px solid #e5e7eb; text-align:left; } .dark .wc-list td { border-color:rgba(255,255,255,.1); }
    </style>

    <x-filament::section>
        <div class="wc-wrap" x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)">
            <div class="wc-clock" x-text="now.toLocaleTimeString('el-GR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></div>

            @if (! $employee)
                <p class="wc-note">Δεν υπάρχει καρτέλα εργαζομένου για τον λογαριασμό σας — ζητήστε από τον διαχειριστή να σας συνδέσει (Προσωπικό → Εργαζόμενοι).</p>
            @else
                <div class="wc-name">{{ $employee->full_name }}</div>
                @if ($viaKiosk)
                    <span class="wc-badge">✓ QR γραφείου</span>
                @elseif ($kioskRequired)
                    <span class="wc-badge wc-warn">Σκανάρετε το QR του γραφείου για να χτυπήσετε κάρτα</span>
                @endif

                @if (! $kioskRequired || $viaKiosk)
                    {{ $this->punchAction }}
                @endif

                @if (! $submits)
                    <p class="wc-note">Η κίνηση καταγράφεται στο ekdosi· δεν δηλώνεται αυτόματα στο ΕΡΓΑΝΗ (η εταιρεία ή ο εργαζόμενος δεν έχει ενεργή ψηφιακή κάρτα).</p>
                @endif

                @if ($today->isNotEmpty())
                    <table class="wc-list">
                        @foreach ($today as $e)
                            <tr>
                                <td>{{ $e->occurred_at->format('H:i') }}</td>
                                <td>{{ $e->typeLabel() }}</td>
                                <td>{{ $e->sourceLabel() }}</td>
                                <td>{{ match ($e->ergani_status) { 'submitted' => 'ΕΡΓΑΝΗ ✓', 'failed' => 'ΕΡΓΑΝΗ ✗', 'unknown' => 'ΕΡΓΑΝΗ ?', 'submitting' => '…', default => '—' } }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endif
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
