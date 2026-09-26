<?php

namespace App\Filament\Support;

use App\Models\CalendarFeed;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;

/**
 * «Ημερολόγιο στο κινητό / Thunderbird» — the user's own read-only ICS link
 * (CalendarFeed) with what to include, «Νέο link» (rotate) and «Ανάκληση».
 * Offered on the leave and leads calendars; each user only ever sees THEIR link.
 */
final class CalendarFeedAction
{
    public static function make(): Action
    {
        return Action::make('calendarFeed')
            ->label('Ημερολόγιο στο κινητό / Thunderbird')
            ->icon('heroicon-o-calendar')
            ->color('gray')
            ->modalHeading('Το ημερολόγιό μου (συνδρομή ICS)')
            ->modalDescription('Ένα προσωπικό link, μόνο για ανάγνωση: το προσθέτετε μία φορά στο Thunderbird / Google Calendar / κινητό και ενημερώνεται μόνο του. Όποιος έχει το link βλέπει το ημερολόγιό σας — μην το μοιράζεστε· αν διαρρεύσει, «Νέο link».')
            ->modalSubmitActionLabel('Αποθήκευση επιλογών')
            ->fillForm(fn (): array => self::feed()->only(['include_leads', 'include_all_leads', 'include_leaves', 'include_team', 'include_holidays', 'include_overtime']))
            ->schema(fn (): array => [
                Placeholder::make('link')->hiddenLabel()->html()
                    ->content(fn (): HtmlString => new HtmlString(
                        '<div style="display:grid;gap:.4rem"><strong>Το link σας:</strong>'
                        .'<code style="word-break:break-all;padding:.4rem .6rem;border-radius:.4rem;background:rgba(0,0,0,.05);user-select:all">'.e(self::feed()->url()).'</code>'
                        .'<small style="opacity:.8">Thunderbird: Νέο ημερολόγιο → «Στο δίκτυο» → επικόλληση. Google: Άλλα ημερολόγια → + → «Από URL». '
                        .'iPhone: Ρυθμίσεις → Ημερολόγιο → Λογαριασμοί → Προσθήκη → Άλλο → «Συνδρομή ημερολογίου». Ανανεώνεται περίπου κάθε ώρα.</small></div>')),
                Section::make('Τι περιλαμβάνει')->columns(2)->schema([
                    Toggle::make('include_leads')->label('Επόμενα βήματα leads')->live(),
                    Toggle::make('include_all_leads')->label('Όλα τα leads της εταιρείας (με τον χειριστή) — όχι μόνο τα δικά μου')
                        ->visible(fn (callable $get): bool => (bool) $get('include_leads')),
                    Toggle::make('include_leaves')->label('Οι άδειές μου')->visible(self::hasHr()),
                    Toggle::make('include_team')->label('Ποιοι συνάδελφοι λείπουν (χωρίς είδος άδειας — δεδομένο υγείας)')->visible(self::hasHr()),
                    Toggle::make('include_holidays')->label('Αργίες')->visible(self::hasHr()),
                    Toggle::make('include_overtime')->label('Οι υπερωρίες μου')->visible(self::hasHr()),
                ]),
            ])
            ->extraModalFooterActions([
                Action::make('rotateCalendarFeed')
                    ->label('Νέο link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Το παλιό link σταματά αμέσως να δουλεύει — θα χρειαστεί να προσθέσετε ξανά το νέο στις εφαρμογές σας.')
                    ->action(function (): void {
                        self::feed()->rotate();
                        Notification::make()->title('Νέο link δημιουργήθηκε — ανοίξτε ξανά το παράθυρο για να το αντιγράψετε')->success()->send();
                    })
                    ->cancelParentActions(),
                Action::make('revokeCalendarFeed')
                    ->label('Ανάκληση')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Το link σταματά να δουλεύει και οι εφαρμογές ημερολογίου δεν θα λαμβάνουν πια τίποτα.')
                    ->action(function (): void {
                        self::feed()->delete();
                        Notification::make()->title('Το link ανακλήθηκε')->success()->send();
                    })
                    ->cancelParentActions(),
            ])
            ->action(function (array $data): void {
                self::feed()->forceFill(array_map('boolval', array_intersect_key($data,
                    array_flip(['include_leads', 'include_all_leads', 'include_leaves', 'include_team', 'include_holidays', 'include_overtime']))))->save();
                Notification::make()->title('Αποθηκεύτηκε')->success()->send();
            });
    }

    private static function hasHr(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && $tenant->hasErgani();
    }

    /** The CURRENT user's feed in the CURRENT company — never anyone else's. */
    private static function feed(): CalendarFeed
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return CalendarFeed::for(auth()->user(), $company);
    }
}
