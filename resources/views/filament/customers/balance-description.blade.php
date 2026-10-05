<span style="display: block; min-width: 0;">
    <span>{{ $breakdown }}</span>

    @if ($aging !== [])
        <span data-ledger-aging style="display: block; margin-top: 0.5rem;">
            <span style="display: block;">Ανάλυση ανεξόφλητων κατά ηλικία</span>
            <span style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.25rem 0.75rem; margin-top: 0.25rem;">
                @foreach ($aging as $label => $amount)
                    <span>
                        <span style="display: block;">{{ $label }}</span>
                        <strong style="display: block;">{{ $amount }}</strong>
                    </span>
                @endforeach
            </span>
        </span>
    @endif
</span>
