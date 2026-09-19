<x-portal-layout title="{{ __('portal.common.my_documents') }}">
    <flux:heading size="xl">{{ __('portal.common.my_documents') }}</flux:heading>
    <flux:text class="mt-2 mb-6">{{ __('portal.home.welcome', ['name' => $user->name]) }}</flux:text>

    @forelse ($groups as $group)
        <div class="mb-8">
            <div class="mb-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <flux:heading size="lg">{{ $group['customer'] }}</flux:heading>
                @if ($group['afm'])
                    <flux:text class="text-sm">{{ __('portal.common.afm') }} {{ $group['afm'] }}</flux:text>
                @endif
                <flux:badge size="sm" color="zinc">{{ $group['company'] }}</flux:badge>
                @if ($group['role'] === 'reseller')
                    <flux:badge size="sm" color="amber">{{ __('portal.common.via_me') }}</flux:badge>
                @endif
            </div>

            @if (count($group['documents']) === 0)
                <flux:text class="text-sm text-zinc-500">{{ __('portal.home.no_documents_yet') }}</flux:text>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                            <tr class="[&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                                <th>{{ __('portal.common.date') }}</th>
                                <th>{{ __('portal.common.document') }}</th>
                                <th>{{ __('portal.common.type') }}</th>
                                <th>{{ __('portal.common.status') }}</th>
                                <th>{{ __('portal.common.mark') }}</th>
                                <th class="text-right">{{ __('portal.common.download') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($group['documents'] as $doc)
                                <tr class="[&>td]:px-4 [&>td]:py-2.5">
                                    <td class="whitespace-nowrap">{{ $doc['issued_at'] ?? '—' }}</td>
                                    <td class="font-medium">{{ $doc['invcode'] ?? '—' }}</td>
                                    <td>{{ $doc['type'] ?? '—' }}</td>
                                    <td>
                                        @if ($doc['mydata_state'] === 'VALID')
                                            <flux:badge size="sm" color="green">{{ __('portal.common.on_mydata') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="blue">{{ __('portal.common.issued') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="font-mono text-xs text-zinc-500">{{ $doc['mydata_mark'] ?? '—' }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="document-arrow-down"
                                            href="{{ route('portal.document.pdf', $doc['id']) }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >{{ __('portal.common.pdf') }}</flux:button>
                                        @if ($doc['verify_url'])
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-top-right-on-square"
                                                href="{{ $doc['verify_url'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >{{ __('portal.common.verify') }}</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($group['truncated'])
                    <flux:text class="mt-2 text-xs text-zinc-500">{{ __('portal.home.recent_note', ['count' => count($group['documents'])]) }}</flux:text>
                @endif
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>{{ __('portal.home.empty') }}</flux:text>
        </div>
    @endforelse
</x-portal-layout>
