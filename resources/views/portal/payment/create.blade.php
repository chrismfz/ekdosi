@php use App\Support\Money; @endphp
<x-portal-layout title="Πληρωμή">
    <div class="mx-auto max-w-md">
        <flux:heading size="xl">Πληρωμή</flux:heading>
        <flux:text class="mt-2 mb-6">{{ $customer->name }}</flux:text>

        @if ($methods->isEmpty())
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text>Δεν υπάρχει διαθέσιμος τρόπος πληρωμής αυτή τη στιγμή. Επικοινώνησε μαζί μας.</flux:text>
            </div>
        @else
            <form method="POST" action="{{ route('portal.payment.store', $company) }}" class="flex flex-col gap-5">
                @csrf
                <flux:input
                    name="amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    label="Ποσό (€)"
                    value="{{ old('amount', number_format($owed, 2, '.', '')) }}"
                    required
                    autofocus
                />
                @if ($owed > 0)
                    <flux:text class="-mt-3 text-sm text-zinc-500">Οφειλόμενο υπόλοιπο: {{ Money::eur($owed) }}</flux:text>
                @endif

                <flux:radio.group label="Τρόπος πληρωμής" name="connection_id" variant="cards">
                    @foreach ($methods as $m)
                        <flux:radio value="{{ $m->id }}" label="{{ $m->label ?: $m->gateway }}" @checked($loop->first) />
                    @endforeach
                </flux:radio.group>

                <flux:button type="submit" variant="primary" class="w-full">Συνέχεια</flux:button>
            </form>
        @endif

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.statement') }}">Επιστροφή στην καρτέλα</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
