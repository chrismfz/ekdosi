<?php

namespace App\Filament\Resources\InvoiceReminders\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\InvoiceReminders\InvoiceReminderResource;
use App\Models\Company;
use App\Models\InvoiceReminder;
use App\Services\Reminders\ReminderPlanner;
use App\Services\Reminders\ReminderRunner;
use App\Services\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListInvoiceReminders extends BaseListRecords
{
    protected static string $resource = InvoiceReminderResource::class;

    /** How far ahead «Επόμενες» looks. */
    public const UPCOMING_DAYS = 14;

    public function getTabs(): array
    {
        $awaiting = InvoiceReminder::query()->where('status', InvoiceReminder::STATUS_AWAITING)->count();
        $failed = InvoiceReminder::query()->where('status', InvoiceReminder::STATUS_FAILED)->count();

        return [
            'all' => Tab::make('Όλες'),
            'awaiting' => Tab::make('Προς έγκριση')
                ->badge($awaiting ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceReminder::STATUS_AWAITING)),
            'sent' => Tab::make('Εστάλησαν')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceReminder::STATUS_SENT)),
            'failed' => Tab::make('Αποτυχίες')
                ->badge($failed ?: null)->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceReminder::STATUS_FAILED)),
            'not_sent' => Tab::make('Δεν στάλθηκαν')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [InvoiceReminder::STATUS_SKIPPED, InvoiceReminder::STATUS_CANCELLED])),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return InvoiceReminder::query()->where('status', InvoiceReminder::STATUS_AWAITING)->exists() ? 'awaiting' : 'all';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upcoming')
                ->label('Επόμενες ('.self::UPCOMING_DAYS.' ημ.)')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Κλείσιμο')
                ->modalHeading('Υπενθυμίσεις των επόμενων '.self::UPCOMING_DAYS.' ημερών')
                ->modalContent(fn (): HtmlString => $this->upcomingHtml()),

            Action::make('settings')
                ->label('Ρυθμίσεις')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn (): string => CompanySettings::getUrl())
                ->visible(fn (): bool => CompanySettings::canAccess()),

            // The daily pass, on demand (e.g. right after switching reminders on).
            // In auto mode it SENDS — so it needs the same right as «Αποστολή».
            Action::make('run_now')
                ->label('Εκτέλεση τώρα')
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => (bool) $this->tenant()?->reminders_enabled)
                ->authorize(fn (): bool => auth()->user()?->can('Update:InvoiceReminder') ?? false)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => ReminderSettings::for($this->tenant())->mode === ReminderSettings::MODE_AUTO
                    ? 'Καταγράφει τις σημερινές υπενθυμίσεις και τις ΣΤΕΛΝΕΙ αμέσως (αυτόματη λειτουργία).'
                    : 'Καταγράφει τις σημερινές υπενθυμίσεις «προς έγκριση» — δεν στέλνεται τίποτα χωρίς εσένα.')
                ->action(function (): void {
                    $r = app(ReminderRunner::class)->run($this->tenant(), CarbonImmutable::today());
                    Notification::make()
                        ->title("Νέες υπενθυμίσεις: {$r['created']}")
                        ->body($r['cancelled'] > 0 ? "Ακυρώθηκαν {$r['cancelled']} (εξοφλήθηκαν ή δεν πληρούν πια τα κριτήρια)." : null)
                        ->success()
                        ->send();
                }),
        ];
    }

    private function tenant(): ?Company
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company ? $tenant : null;
    }

    /** The first day each document would get a reminder in the coming window. */
    private function upcomingHtml(): HtmlString
    {
        $company = $this->tenant();
        if ($company === null || ! $company->reminders_enabled) {
            return new HtmlString('<p>Οι υπενθυμίσεις είναι ανενεργές — ενεργοποίησέ τες στις «Ρυθμίσεις εταιρείας».</p>');
        }

        $rows = '';
        foreach (app(ReminderPlanner::class)->upcoming($company, CarbonImmutable::today(), self::UPCOMING_DAYS) as $p) {
            $rows .= '<tr>'
                .'<td style="padding:.25rem .5rem">'.e($p['date']->format('d/m')).'</td>'
                .'<td style="padding:.25rem .5rem">'.e(InvoiceReminder::STAGE_LABELS[$p['stage']] ?? $p['stage']).'</td>'
                .'<td style="padding:.25rem .5rem">'.e((string) $p['invoice']->invcode)
                .(ReminderPlanner::kindOf($p['invoice']) === InvoiceReminder::KIND_PROFORMA ? ' <em>(προτιμολόγιο)</em>' : '').'</td>'
                .'<td style="padding:.25rem .5rem">'.e((string) $p['invoice']->customer?->name).'</td>'
                .'<td style="padding:.25rem .5rem;text-align:right">'.e(number_format($p['balance'], 2, ',', '.')).' €</td>'
                .'</tr>';
        }

        if ($rows === '') {
            return new HtmlString('<p>Καμία υπενθύμιση τις επόμενες '.self::UPCOMING_DAYS.' ημέρες.</p>');
        }

        return new HtmlString(
            '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left;padding:.25rem .5rem">Ημέρα</th><th style="text-align:left;padding:.25rem .5rem">Βαθμίδα</th>'
            .'<th style="text-align:left;padding:.25rem .5rem">Παραστατικό</th><th style="text-align:left;padding:.25rem .5rem">Πελάτης</th>'
            .'<th style="text-align:right;padding:.25rem .5rem">Υπόλοιπο</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p style="margin-top:.5rem;opacity:.75">Υπολογίζεται με τα σημερινά δεδομένα — ό,τι εξοφληθεί στο μεταξύ δεν θα σταλεί.</p>'
        );
    }
}
