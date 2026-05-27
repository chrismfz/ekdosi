{{-- PR #34 followup: per-customer WHMCS comparison table.
     Rendered inside a Filament modal opened from the EditCustomer
     "WHMCS invoices" action. Operator picks a customer in ekdosi and
     sees side-by-side: WHMCS invoices for this client + ekdosi-side
     state for each (staged / historic / absent). Read-only. --}}

@php /** @var \App\Services\Whmcs\CustomerWhmcsLedgerResult $result */ @endphp

@if ($result->error)
    <div class="rounded-lg bg-danger-50 dark:bg-danger-950/40 p-4 text-sm text-danger-700 dark:text-danger-200">
        <strong>Error fetching from WHMCS:</strong>
        <div class="mt-1">{{ $result->error }}</div>
    </div>
@elseif ($result->isEmpty())
    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4 text-sm text-gray-600 dark:text-gray-300">
        No WHMCS invoices found for this client.
        @if ($customer->company?->whmcs_invoice_min_date)
            <div class="mt-1 text-xs">
                Tenant cutoff date is set to
                <code>{{ $customer->company->whmcs_invoice_min_date->format('Y-m-d') }}</code> —
                invoices older than this are filtered out. Clear the cutoff on the Company form
                to see everything.
            </div>
        @endif
    </div>
@else
    {{-- Summary stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="rounded-lg bg-gray-100 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">WHMCS Total</div>
            <div class="text-xl font-semibold">{{ $result->total() }}</div>
        </div>
        <div class="rounded-lg bg-success-50 dark:bg-success-950/40 p-3">
            <div class="text-xs text-success-700 dark:text-success-300 uppercase">Historic in ekdosi</div>
            <div class="text-xl font-semibold text-success-700 dark:text-success-300">{{ $result->historicCount }}</div>
        </div>
        <div class="rounded-lg bg-info-50 dark:bg-info-950/40 p-3">
            <div class="text-xs text-info-700 dark:text-info-300 uppercase">Staged for review</div>
            <div class="text-xl font-semibold text-info-700 dark:text-info-300">{{ $result->stagedCount }}</div>
        </div>
        <div class="rounded-lg bg-warning-50 dark:bg-warning-950/40 p-3">
            <div class="text-xs text-warning-700 dark:text-warning-300 uppercase">Absent in ekdosi</div>
            <div class="text-xl font-semibold text-warning-700 dark:text-warning-300">{{ $result->absentCount }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold">WHMCS #</th>
                    <th class="px-3 py-2 text-left font-semibold">Date</th>
                    <th class="px-3 py-2 text-left font-semibold">Paid</th>
                    <th class="px-3 py-2 text-right font-semibold">Total</th>
                    <th class="px-3 py-2 text-left font-semibold">Status</th>
                    <th class="px-3 py-2 text-left font-semibold">WHMCS invoiced flag</th>
                    <th class="px-3 py-2 text-left font-semibold">ekdosi state</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($result->rows as $row)
                    @php
                        $state = $row['ekdosi_state'];
                        [$badgeColor, $badgeText] = match ($state['kind']) {
                            'historic' => [
                                'success',
                                'Filed historically (#' . ($state['invoice_invcode'] ?? $state['invoice_id']) . ')',
                            ],
                            'staged' => [match ($state['status']) {
                                'pending_review' => 'info',
                                'filed' => 'success',
                                'rejected' => 'danger',
                                'held' => 'warning',
                                default => 'gray',
                            }, ucfirst(str_replace('_', ' ', $state['status']))
                              . ($state['mark'] ? ' (MARK ' . $state['mark'] . ')' : '')],
                            default => ['warning', 'Not in ekdosi'],
                        };
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-3 py-2 font-mono">{{ $row['whmcs_id'] }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['date'] }}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600 dark:text-gray-400">
                            {{ $row['datepaid'] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-right font-mono">
                            {{ $row['total'] }} {{ $row['currency'] }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-2 py-0.5 rounded-full
                                {{ $row['status'] === 'Paid' ? 'bg-success-100 text-success-700 dark:bg-success-950/40 dark:text-success-300' : '' }}
                                {{ $row['status'] === 'Unpaid' ? 'bg-warning-100 text-warning-700 dark:bg-warning-950/40 dark:text-warning-300' : '' }}
                                {{ in_array($row['status'], ['Cancelled', 'Refunded'], true) ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' : '' }}">
                                {{ $row['status'] }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if ($row['invoiced_flag'] === null)
                                <span class="text-gray-400">— (column absent)</span>
                            @elseif ($row['invoiced_flag'] === 0)
                                <span class="text-warning-600 dark:text-warning-400">0 (unfiled)</span>
                            @else
                                <span class="text-success-600 dark:text-success-400">{{ $row['invoiced_flag'] }} (filed)</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-2 py-1 rounded-full
                                {{ $badgeColor === 'success' ? 'bg-success-100 text-success-700 dark:bg-success-950/40 dark:text-success-300' : '' }}
                                {{ $badgeColor === 'info' ? 'bg-info-100 text-info-700 dark:bg-info-950/40 dark:text-info-300' : '' }}
                                {{ $badgeColor === 'warning' ? 'bg-warning-100 text-warning-700 dark:bg-warning-950/40 dark:text-warning-300' : '' }}
                                {{ $badgeColor === 'danger' ? 'bg-danger-100 text-danger-700 dark:bg-danger-950/40 dark:text-danger-300' : '' }}
                                {{ $badgeColor === 'gray' ? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' : '' }}">
                                {{ $badgeText }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Showing latest {{ $result->total() }} WHMCS invoices for client #{{ $customer->whmcs_client_id }}.
        @if ($customer->company?->whmcs_invoice_min_date)
            Cutoff: invoices older than
            <code>{{ $customer->company->whmcs_invoice_min_date->format('Y-m-d') }}</code> excluded.
        @endif
    </div>
@endif
