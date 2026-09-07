<x-portal-layout title="Νέο αίτημα">
    <div class="mb-4">
        <flux:link href="{{ route('portal.tickets') }}" class="text-sm">← Τα αιτήματά μου</flux:link>
    </div>
    <flux:heading size="xl">Νέο αίτημα υποστήριξης</flux:heading>
    <flux:text class="mt-2 mb-6">Περίγραψε το θέμα σου και θα σου απαντήσουμε το συντομότερο.</flux:text>

    @if (empty($options))
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>Δεν υπάρχει διαθέσιμο τμήμα υποστήριξης αυτή τη στιγμή. Δοκίμασε αργότερα.</flux:text>
        </div>
    @else
        <form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data" class="flex max-w-xl flex-col gap-5">
            @csrf

            {{-- Native <select> on purpose: flux:select renders blank without a Vite build (portal decision). --}}
            <div>
                <label for="target" class="mb-1 block text-sm font-medium">Τμήμα</label>
                <select name="target" id="target" required
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    @foreach ($options as $opt)
                        <option value="{{ $opt['value'] }}" @selected(old('target') === $opt['value'])>{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                @error('target') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div>
                <flux:input name="subject" label="Θέμα" value="{{ old('subject') }}" required maxlength="191" />
                @error('subject') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div>
                <label for="priority" class="mb-1 block text-sm font-medium">Προτεραιότητα</label>
                <select name="priority" id="priority"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    <option value="low" @selected(old('priority') === 'low')>Χαμηλή</option>
                    <option value="normal" @selected(old('priority', 'normal') === 'normal')>Κανονική</option>
                    <option value="high" @selected(old('priority') === 'high')>Υψηλή</option>
                </select>
            </div>

            <div>
                <flux:textarea name="body" label="Περιγραφή" rows="6" required>{{ old('body') }}</flux:textarea>
                @error('body') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Συνημμένα (προαιρετικά, έως {{ \App\Support\TicketAttachments::MAX_COUNT }} αρχεία)</label>
                <input type="file" name="attachments[]" multiple class="text-sm">
                @error('attachments.*') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Αποστολή</flux:button>
                <flux:button variant="ghost" href="{{ route('portal.tickets') }}">Άκυρο</flux:button>
            </div>
        </form>
    @endif
</x-portal-layout>
