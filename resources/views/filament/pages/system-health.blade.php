<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Read-only εικόνα του <code>php artisan ops:health</code>. Ενημερώθηκε
        <strong>{{ $this->ago($report['generated_at'] ?? null) }}</strong> — «Ανανέωση» για φρέσκο.
    </div>

    {{-- Έκδοση / ενημερώσεις (read-only — το apply μένει στο deploy/update.sh) --}}
    <x-filament::section>
        <x-slot name="heading">Έκδοση</x-slot>
        @php($u = $update ?? [])
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-filament::badge color="gray">
                {{ ($u['current_version'] ?? null) ? 'v'.$u['current_version'] : '—' }}
            </x-filament::badge>
            <span>
                Build: <strong>{{ $u['current_build'] ?? '—' }}</strong>
                @if ($u['current_sha'] ?? null)
                    <span class="text-gray-500 dark:text-gray-400">({{ $u['current_sha'] }})</span>
                @endif
            </span>

            @if (! ($u['enabled'] ?? true))
                <x-filament::badge color="gray">Έλεγχος ενημερώσεων: ανενεργός</x-filament::badge>
            @elseif (! ($u['ok'] ?? false))
                <x-filament::badge color="warning">Έλεγχος: {{ $u['error'] ?? 'απέτυχε' }}</x-filament::badge>
            @elseif ($u['update_available'] ?? false)
                <x-filament::badge color="warning">
                    Νέα έκδοση: {{ $u['latest_version'] }}@if (is_int($u['commits_behind'] ?? null) && $u['commits_behind'] > 0) · {{ $u['commits_behind'] }} commits πίσω@endif
                </x-filament::badge>
                @if ($u['url'] ?? null)
                    <x-filament::button tag="a" href="{{ $u['url'] }}" target="_blank" rel="noopener" size="xs" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                        Δες τι άλλαξε
                    </x-filament::button>
                @endif
            @else
                <x-filament::badge color="success">Ενημερωμένο ({{ $u['latest_version'] }})</x-filament::badge>
            @endif
        </div>

        @if ($u['checked_at'] ?? null)
            <div class="mt-3 space-y-1 text-sm text-gray-500 dark:text-gray-400">
                Τελευταίος έλεγχος: {{ $this->ago($u['checked_at']) }}@if ($u['stale'] ?? false) (παλιό αποτέλεσμα — offline;)@endif
            </div>
        @endif
    </x-filament::section>

    {{-- OPS-4: distilled verdict banner --}}
    @php($sev = $report['severity'] ?? ['level' => 'ok', 'critical' => [], 'warnings' => []])
    @if (($sev['level'] ?? 'ok') !== 'ok')
        <x-filament::section>
            <x-slot name="heading">
                <x-filament::badge :color="($sev['level'] === 'critical') ? 'danger' : 'warning'">
                    {{ $sev['level'] === 'critical' ? '✗ Κρίσιμη κατάσταση' : '⚠ Προειδοποιήσεις' }}
                </x-filament::badge>
            </x-slot>
            <ul class="list-disc ps-4 text-sm space-y-1">
                @foreach (($sev['critical'] ?? []) as $line)
                    <li class="text-danger-600 dark:text-danger-400">{{ $line }}</li>
                @endforeach
                @foreach (($sev['warnings'] ?? []) as $line)
                    <li class="text-warning-600 dark:text-warning-400">{{ $line }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- Queue --}}
    <x-filament::section>
        <x-slot name="heading">Ουρά εργασιών (queue)</x-slot>
        @php($q = $report['queue'] ?? [])
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-filament::badge :color="$this->statusColor($q['worker_heartbeat_status'] ?? null)">
                Worker: {{ $this->statusLabel($q['worker_heartbeat_status'] ?? null) }}
            </x-filament::badge>
            <span>Τελευταίο heartbeat: <strong>{{ $this->ago($q['worker_heartbeat_at'] ?? null) }}</strong></span>
            <x-filament::badge color="gray">
                Pending jobs: {{ $q['pending_jobs'] ?? '—' }}
            </x-filament::badge>
            <x-filament::badge :color="($q['failed_jobs'] ?? 0) > 0 ? 'danger' : 'success'">
                Failed jobs: {{ $q['failed_jobs'] ?? '—' }}
            </x-filament::badge>
        </div>
    </x-filament::section>

    {{-- Scheduler --}}
    <x-filament::section>
        <x-slot name="heading">Χρονοπρογραμματιστής (τι έτρεξε)</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Εργασία</th><th>Κατάσταση</th><th>Τελευταία εκτέλεση</th><th>Exit</th></tr></thead>
            <tbody>
            @foreach (($report['scheduler'] ?? []) as $task)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="py-1">{{ $task['label'] ?? '—' }}</td>
                    <td><x-filament::badge :color="$this->statusColor($task['status'] ?? null)">{{ $this->statusLabel($task['status'] ?? null) }}</x-filament::badge></td>
                    <td>{{ $this->ago($task['last_run_at'] ?? null) }}</td>
                    <td>{{ $task['exit_code'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>

    {{-- Recent runs (durable history) --}}
    @if (!empty($report['recent_runs']))
    <x-filament::section>
        <x-slot name="heading">Πρόσφατες εκτελέσεις (ιστορικό)</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Εργασία</th><th>Κατάσταση</th><th>Ξεκίνησε</th><th>Διάρκεια</th><th>Exit</th><th>Σημείωση</th></tr></thead>
            <tbody>
            @foreach ($report['recent_runs'] as $run)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="py-1">{{ $run['label'] ?? $run['task'] ?? '—' }}</td>
                    <td><x-filament::badge :color="$this->statusColor($run['status'] ?? null)">{{ $this->statusLabel($run['status'] ?? null) }}</x-filament::badge></td>
                    <td>{{ $this->ago($run['started_at'] ?? null) }}</td>
                    <td>{{ $this->ms($run['duration_ms'] ?? null) }}</td>
                    <td>{{ $run['exit_code'] ?? '—' }}</td>
                    <td class="text-gray-500">{{ $run['summary'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>
    @endif

    {{-- Backup + Mail (side by side). Anchor: the Χρονοπρογραμματιστής links here (#backups). --}}
    <div id="backups" style="scroll-margin-top: 5rem;"></div>
    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Αντίγραφα ασφαλείας</x-slot>
            @php($b = $report['backup'] ?? [])
            <div class="space-y-1 text-sm">
                <div><x-filament::badge :color="$this->statusColor($b['monitor']['status'] ?? null)">Monitor: {{ $this->statusLabel($b['monitor']['status'] ?? null) }}</x-filament::badge></div>
                <div>Τελευταίο τοπικό: <strong>{{ $this->ago($b['latest_backup_at'] ?? null) }}</strong>
                    @if(isset($b['latest_backup_age_hours'])) ({{ $b['latest_backup_age_hours'] }}h) @endif</div>
                <div>Μέγεθος (τελευταίο): {{ $this->bytes($b['latest_backup_size_bytes'] ?? null) }}</div>
            </div>

            {{-- The whole-DB (spatie) artifacts themselves: where they live, how
                 many, total size, and the newest few with size + timestamp. --}}
            @php($lf = $b['local_files'] ?? [])
            @if (($b['local_count'] ?? 0) > 0)
                <div class="mt-3 space-y-1 text-sm">
                    <div class="text-gray-500">Αρχεία αντιγράφων ΒΔ</div>
                    <div>Φάκελος: <span class="font-mono text-xs">{{ $b['local_dir'] }}</span></div>
                    <div>Πλήθος: <strong>{{ $b['local_count'] }}</strong> · Σύνολο: <strong>{{ $this->bytes($b['local_total_bytes'] ?? null) }}</strong></div>
                    <div class="mt-1 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead><tr class="text-left text-gray-500"><th class="py-1 pr-3">Αρχείο</th><th class="pr-3">Μέγεθος</th><th>Ημ/νία</th></tr></thead>
                            <tbody>
                            @foreach ($lf as $f)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-1 pr-3 font-mono">{{ $f['name'] }}</td>
                                    <td class="pr-3">{{ $this->bytes($f['size_bytes'] ?? null) }}</td>
                                    <td>{{ $this->ago($f['modified_at'] ?? null) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (($b['local_count'] ?? 0) > count($lf))
                        <div class="text-gray-500">…και {{ $b['local_count'] - count($lf) }} ακόμη (τα {{ count($lf) }} πιο πρόσφατα)</div>
                    @endif
                </div>
            @else
                <div class="mt-2 text-sm text-gray-500">Κανένα τοπικό αρχείο αντιγράφου ΒΔ{{ isset($b['local_dir']) ? ' ('.$b['local_dir'].')' : '' }}.</div>
            @endif

            {{-- Per-tenant off-site + books (parity with CLI ops:health) --}}
            @php($cb = $b['companies'] ?? [])
            @if (($cb['enabled_count'] ?? 0) > 0)
                <div class="mt-3 space-y-1 text-sm">
                    <div class="text-gray-500">Ανά εταιρία (αυτόματα αντίγραφα)</div>
                    @foreach (($cb['companies'] ?? []) as $row)
                        <div class="flex flex-wrap items-center gap-2">
                            <strong>{{ $row['slug'] ?? '—' }}</strong>
                            <x-filament::badge :color="($row['offsite_configured'] ?? false) ? 'success' : 'warning'">
                                {{ ($row['offsite_configured'] ?? false) ? 'εκτός VM' : 'μόνο τοπικά' }}
                            </x-filament::badge>
                            <x-filament::badge :color="($row['books_included'] ?? false) ? 'success' : 'warning'">
                                {{ ($row['books_included'] ?? false) ? 'με βιβλία' : 'ρυθμίσεις μόνο' }}
                            </x-filament::badge>
                            @if (($row['offsite_push_ok'] ?? null) === false)
                                <x-filament::badge color="danger">off-site push απέτυχε</x-filament::badge>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Email</x-slot>
            @php($m = $report['mail'] ?? [])
            <div class="space-y-1 text-sm">
                <x-filament::badge :color="($m['failed_24h'] ?? 0) > 0 ? 'danger' : 'success'">Αποτυχίες 24ω: {{ $m['failed_24h'] ?? '—' }}</x-filament::badge>
                <div>Αποτυχίες 7ημ: {{ $m['failed_7d'] ?? '—' }}</div>
                <div>Κολλημένα (queued/sending): <strong>{{ $m['stuck_queued_or_sending'] ?? '—' }}</strong></div>
                <div>Τελευταία αποτυχία: {{ $this->ago($m['latest_failure_at'] ?? null) }}</div>
            </div>
        </x-filament::section>
    </div>

    {{-- WHMCS per tenant --}}
    @if (!empty($report['whmcs']))
    <x-filament::section>
        <x-slot name="heading">WHMCS (ανά εταιρία)</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Tenant</th><th>Κατάσταση</th><th>Τελ. επιτυχία</th><th>Εκκρεμή inbox</th></tr></thead>
            <tbody>
            @foreach ($report['whmcs'] as $w)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="py-1">{{ $w['tenant'] ?? '—' }}</td>
                    <td><x-filament::badge :color="$this->statusColor($w['status'] ?? null)">{{ $this->statusLabel($w['status'] ?? null) }}</x-filament::badge>@if ($w['stale'] ?? false) <x-filament::badge color="warning">κόλλησε</x-filament::badge>@endif</td>
                    <td>{{ $this->ago($w['last_success_at'] ?? null) }}</td>
                    <td>{{ $w['pending_review'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>
    @endif

    {{-- myDATA per tenant --}}
    @if (!empty($report['mydata']))
    <x-filament::section>
        <x-slot name="heading">myDATA reconcile (ανά εταιρία)</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Tenant</th><th>Κατάσταση</th><th>Αποκλίσεις</th><th>Τελ. επιτυχία</th></tr></thead>
            <tbody>
            @foreach ($report['mydata'] as $d)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="py-1">{{ $d['tenant'] ?? '—' }}</td>
                    <td><x-filament::badge :color="$this->statusColor($d['status'] ?? null)">{{ $this->statusLabel($d['status'] ?? null) }}</x-filament::badge>@if ($d['stale'] ?? false) <x-filament::badge color="warning">κόλλησε</x-filament::badge>@endif</td>
                    <td>{{ $d['discrepancies'] ?? '—' }}</td>
                    <td>{{ $this->ago($d['last_success_at'] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>
    @endif

    {{-- Disk --}}
    <x-filament::section>
        <x-slot name="heading">Δίσκος</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Διαδρομή</th><th>Σε χρήση</th><th>Ελεύθερα</th><th>Σύνολο</th></tr></thead>
            <tbody>
            @foreach (($report['disk'] ?? []) as $name => $d)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="py-1">{{ $name }}</td>
                    <td>{{ $this->bytes($d['used_bytes'] ?? null) }}</td>
                    <td>{{ $this->bytes($d['free_bytes'] ?? null) }}</td>
                    <td>{{ $this->bytes($d['total_bytes'] ?? null) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
