<x-filament-widgets::widget>
    <style>
        .tt { display:grid; grid-template-columns:repeat(auto-fit, minmax(14rem, 1fr)); gap:1rem; font-size:.9rem; }
        .tt h4 { margin:0 0 .3rem; font-size:.8rem; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; }
        .dark .tt h4 { color:#9ca3af; }
        .tt ul { margin:0; padding-left:1.1rem; }
        .tt li { margin:.1rem 0; }
        .tt-none { color:#6b7280; } .dark .tt-none { color:#9ca3af; }
        .tt-big { font-size:1.5rem; font-weight:700; }
        .tt-warn { color:#b45309; } .dark .tt-warn { color:#fbbf24; }
        .tt a { color:#2563eb; text-decoration:underline; } .dark .tt a { color:#60a5fa; }
    </style>
    <x-filament::section heading="Η ομάδα σήμερα" icon="heroicon-o-user-group">
        @if ($calendarUrl)
            <x-slot name="afterHeader"><a href="{{ $calendarUrl }}" style="font-size:.85rem">Ημερολόγιο αδειών →</a></x-slot>
        @endif
        <div class="tt">
            <div>
                <h4>Λείπουν σήμερα</h4>
                @if ($holiday)
                    <div class="tt-none">Αργία: {{ $holiday }}@if ($awayToday) — σε άδεια που συνεχίζεται:@endif</div>
                @elseif ($weekend)
                    <div class="tt-none">Σαββατοκύριακο@if ($awayToday) — σε άδεια που συνεχίζεται:@endif</div>
                @endif
                @if ($awayToday)
                    <ul>@foreach ($awayToday as $line)<li>{{ $line }}</li>@endforeach</ul>
                @elseif (! $holiday && ! $weekend)
                    <div class="tt-none">Κανείς — όλοι εδώ.</div>
                @endif
            </div>
            <div>
                <h4>Επόμενες 7 ημέρες</h4>
                @if ($laterThisWeek)
                    <ul>@foreach ($laterThisWeek as $line)<li>{{ $line }}</li>@endforeach</ul>
                @else
                    <div class="tt-none">Καμία άδεια.</div>
                @endif
            </div>
            @if ($approver)
                <div>
                    <h4>Περιμένουν έγκριση</h4>
                    @if ($pendingUrl)
                        <a href="{{ $pendingUrl }}" class="tt-big tt-warn">{{ $pending }}</a>
                        <div>αιτήματα άδειας — <a href="{{ $pendingUrl }}">άνοιγμα</a></div>
                    @else
                        <div class="tt-none">Κανένα.</div>
                    @endif
                </div>
            @endif
            @if ($presence !== null)
                <div>
                    <h4>Μέσα τώρα (κάρτα)</h4>
                    <div class="tt-big">{{ count($presence['in']) }} / {{ $presence['total'] }}</div>
                    @if ($presence['in'])
                        <ul>@foreach ($presence['in'] as $line)<li>{{ $line }}</li>@endforeach</ul>
                    @endif
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
