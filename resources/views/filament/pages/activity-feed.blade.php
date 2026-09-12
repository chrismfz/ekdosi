<x-filament-panels::page>
    <x-filament::tabs>
        <x-filament::tabs.item
            icon="heroicon-o-clock"
            :active="$this->effectiveActiveTab() === 'records'"
            wire:click="$set('activeTab', 'records')"
        >
            Εγγραφές
        </x-filament::tabs.item>

        @if ($this->canSeeSecurityTab())
            <x-filament::tabs.item
                icon="heroicon-o-shield-check"
                :active="$this->effectiveActiveTab() === 'security'"
                wire:click="$set('activeTab', 'security')"
            >
                Συνδέσεις &amp; ασφάλεια
            </x-filament::tabs.item>
        @endif
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
