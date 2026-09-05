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
            <form method="POST" action="{{ route('portal.payment.store', $customer->id) }}" class="flex flex-col gap-5">
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

                {{-- Native radios (not flux:radio.group variant="cards"): the Flux
                     card group renders a <ui-radio> web-component that shows NOTHING
                     until the Vite/Flux build is served, so on a box without
                     `npm run build` the customer saw an empty «Τρόπος πληρωμής» and
                     could not pay. A native radio always renders (styled when the
                     build is present, plain but functional when it is not). --}}
                <div>
                    <flux:text class="mb-2 font-medium">Τρόπος πληρωμής</flux:text>
                    <div class="flex flex-col gap-2">
                        @foreach ($methods as $m)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 has-[:checked]:border-zinc-900 has-[:checked]:ring-1 has-[:checked]:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:has-[:checked]:border-white dark:has-[:checked]:ring-white">
                                <input type="radio" name="connection_id" value="{{ $m->id }}" required
                                    @checked((int) old('connection_id', $loop->first ? $m->id : null) === $m->id)
                                    class="h-4 w-4 accent-zinc-900 dark:accent-white" />
                                <span>{{ $m->label ?: $m->gateway }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <flux:button type="submit" variant="primary" class="w-full">Συνέχεια</flux:button>
            </form>
        @endif

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.statement') }}">Επιστροφή στην καρτέλα</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
