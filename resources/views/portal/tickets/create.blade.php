<x-portal-layout title="{{ __('portal.tickets.new') }}">
    <div class="mb-4">
        <flux:link href="{{ route('portal.tickets') }}" class="text-sm">← {{ __('portal.common.my_requests') }}</flux:link>
    </div>
    <flux:heading size="xl">{{ __('portal.tickets.new_heading') }}</flux:heading>
    <flux:text class="mt-2 mb-6">{{ __('portal.tickets.new_intro') }}</flux:text>

    @if (empty($options))
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>{{ __('portal.tickets.no_department') }}</flux:text>
        </div>
    @else
        <form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data" class="flex max-w-xl flex-col gap-5">
            @csrf

            {{-- Native <select> on purpose: flux:select renders blank without a Vite build (portal decision). --}}
            <div>
                <label for="target" class="mb-1 block text-sm font-medium">{{ __('portal.common.department') }}</label>
                <select name="target" id="target" required
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    @foreach ($options as $opt)
                        <option value="{{ $opt['value'] }}" @selected(old('target') === $opt['value'])>{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                @error('target') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div>
                <flux:input name="subject" label="{{ __('portal.common.subject') }}" value="{{ old('subject') }}" required maxlength="191" />
                @error('subject') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div>
                <label for="priority" class="mb-1 block text-sm font-medium">{{ __('portal.tickets.priority') }}</label>
                <select name="priority" id="priority"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    <option value="low" @selected(old('priority') === 'low')>{{ __('portal.tickets.priority_low') }}</option>
                    <option value="normal" @selected(old('priority', 'normal') === 'normal')>{{ __('portal.tickets.priority_normal') }}</option>
                    <option value="high" @selected(old('priority') === 'high')>{{ __('portal.tickets.priority_high') }}</option>
                </select>
            </div>

            <div>
                <flux:textarea name="body" label="{{ __('portal.tickets.description') }}" rows="6" required>{{ old('body') }}</flux:textarea>
                @error('body') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('portal.tickets.attachments', ['max' => \App\Support\TicketAttachments::MAX_COUNT]) }}</label>
                <input type="file" name="attachments[]" multiple class="text-sm">
                @error('attachments.*') <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('portal.tickets.send') }}</flux:button>
                <flux:button variant="ghost" href="{{ route('portal.tickets') }}">{{ __('portal.common.cancel') }}</flux:button>
            </div>
        </form>
    @endif
</x-portal-layout>
