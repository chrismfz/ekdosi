<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Customers\MergeCustomers;
use App\Services\Whmcs\WhmcsClientFactory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * Editing the ΑΦΜ onto one another operator just created: the form rule
     * passed a moment ago, UNIQUE(company_id, afm_key) wins now — say so.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (UniqueConstraintViolationException) {
            Notification::make()
                ->title('Υπάρχει ήδη πελάτης με αυτό το ΑΦΜ')
                ->body('Δημιουργήθηκε μόλις τώρα από άλλον χειριστή. Βρες τον στη λίστα πελατών· αυτή η αλλαγή δεν αποθηκεύτηκε.')
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Καρτέλα: the financial view, primary destination for
            // operators looking at a customer's account.
            Action::make('open_kartela')
                ->label('Καρτέλα')
                ->icon('heroicon-o-document-chart-bar')
                ->color('primary')
                ->url(fn (Customer $record) => CustomerResource::getUrl('ledger', ['record' => $record])),

            // PR #28: WHMCS linking action. Visible only when the
            // tenant has WHMCS configured — otherwise it'd offer
            // nothing useful. Two flows:
            //   - Search-by-name picker that calls WHMCS GetClients
            //     live; operator selects from candidates.
            //   - Manual ID entry for the "I already know the
            //     WHMCS client id" case (legacy data imports).
            Action::make('link_whmcs')
                ->label(fn (Customer $record) => $record->whmcs_client_id
                    ? 'Re-link WHMCS (currently #'.$record->whmcs_client_id.')'
                    : 'Link to WHMCS client')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn (Customer $record) => $record->company?->hasWhmcsIntegration() ?? false)
                ->authorize(fn (Customer $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Link this customer to a WHMCS client')
                ->modalDescription('Search WHMCS by name, email, or company. Pick a candidate to set customers.whmcs_client_id, which Stage B (PR #29) will use to route pulled invoices.')
                ->modalSubmitActionLabel('Save link')
                ->schema(fn (Customer $record) => [
                    // Filament-correct shape for "live-fetch options
                    // from an external source": searchable Select
                    // with getSearchResultsUsing(). Filament fires
                    // the closure ONLY when the operator types in the
                    // Select's built-in search box, debounced
                    // automatically. Returns a [id => label] map.
                    // The previous shape (->options(closure) reading
                    // an external TextInput) re-fired on every form
                    // re-render — easily 4-6 WHMCS API calls per
                    // operator interaction.
                    Select::make('whmcs_client_id')
                        ->label('Search WHMCS')
                        ->searchable()
                        ->getSearchResultsUsing(function (string $search) use ($record): array {
                            $needle = trim($search);
                            if ($needle === '') {
                                return [];
                            }
                            try {
                                $client = app(WhmcsClientFactory::class)->for($record->company);
                                $hits = $client->searchClients($needle, limit: 25);
                            } catch (WhmcsApiException $e) {
                                // Live search can't easily surface an
                                // exception in the dropdown. Empty
                                // result + the operator clicks Test
                                // Connection on the Company form to
                                // diagnose. Better than a broken
                                // modal that won't dismiss.
                                return [];
                            }

                            $options = [];
                            foreach ($hits as $h) {
                                $id = (int) ($h['id'] ?? 0);
                                if ($id === 0) {
                                    continue;
                                }
                                $name = trim((string) ($h['companyname'] ?? ''));
                                if ($name === '') {
                                    $name = trim(($h['firstname'] ?? '').' '.($h['lastname'] ?? ''));
                                }
                                $email = (string) ($h['email'] ?? '');
                                $options[$id] = '#'.$id.' — '.($name ?: '(no name)')
                                    .($email !== '' ? ' <'.$email.'>' : '');
                            }

                            return $options;
                        })
                        ->getOptionLabelUsing(function ($value) use ($record): ?string {
                            // Render a CHEAP LOCAL label here — no
                            // WHMCS API call on label render. The
                            // earlier shape (calling getClient() to
                            // surface the WHMCS-side name) stalled
                            // the modal's initial render on a
                            // synchronous HTTP call for up to 25s on
                            // a network blackhole. Verified at
                            // vendor/filament/forms/resources/views/
                            // components/select.blade.php:172 —
                            // getOptionLabelUsing fires inline during
                            // server-side render; Choices.js can
                            // also re-fire it asynchronously on
                            // selection switches, multiplying the
                            // cost. The previous shape's claim to
                            // "fix per-render API calls" was only
                            // true for the search closure; this one
                            // re-introduced the problem on the label
                            // render path.
                            //
                            // Defense-in-depth: also enforce tenant
                            // boundary on the closure since Filament
                            // exposes it as a Livewire-callable
                            // endpoint. A cross-tenant URL bug would
                            // otherwise let this closure leak a
                            // different tenant's customer detail.
                            // (Currently moot since BelongsToTenant
                            // scopes the Customer query above, but
                            // worth the explicit check at every
                            // closure that takes a captured model.)
                            if (! $value) {
                                return null;
                            }
                            if ($record->company_id !== Filament::getTenant()?->getKey()) {
                                return '#'.$value;
                            }

                            return 'Currently linked to WHMCS client #'.$value;
                        })
                        ->default($record->whmcs_client_id)
                        ->helperText('Type to search WHMCS by name, email, or company. Pick a candidate, or leave blank + Save to UNLINK.'),

                    TextInput::make('manual_id')
                        ->label('Or set the ID manually')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('e.g. 4321')
                        ->helperText('Overrides the picker. Use if you already know the WHMCS client id.'),
                ])
                ->action(function (array $data, Customer $record) {
                    $manual = (int) ($data['manual_id'] ?? 0);
                    $picked = (int) ($data['whmcs_client_id'] ?? 0);

                    // Manual ID takes precedence (operator typed it
                    // explicitly). Then the picker. Empty both = unlink.
                    $newId = $manual ?: $picked ?: null;

                    $record->update(['whmcs_client_id' => $newId]);

                    Notification::make()
                        ->title($newId
                            ? "Linked to WHMCS client #{$newId}"
                            : 'Unlinked from WHMCS')
                        ->success()
                        ->send();
                }),

            // «Συγχώνευση» — the same party entered twice (a legacy row + a
            // panel/WHMCS one). Picks the other row, shows EXACTLY what will
            // move, then hands off to MergeCustomers (one transaction; the
            // other row is force-deleted and its differing fields land as a
            // pinned note here). Gated on Delete:Customer — it destroys a row.
            Action::make('merge_customer')
                ->label('Συγχώνευση με άλλον πελάτη')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('danger')
                ->authorize(fn (Customer $record) => (auth()->user()?->can('update', $record) ?? false)
                    && (auth()->user()?->can('delete', $record) ?? false))
                ->modalHeading('Συγχώνευση πελατών')
                ->modalDescription('Ο πελάτης που θα διαλέξεις ΔΙΑΓΡΑΦΕΤΑΙ ΟΡΙΣΤΙΚΑ και όλα του (παραστατικά, πληρωμές, προσφορές…) περνούν σε αυτόν εδώ. Δεν αναιρείται.')
                ->modalSubmitActionLabel('Συγχώνευση')
                ->schema([
                    Select::make('drop_id')
                        ->label('Πελάτης που θα συγχωνευθεί (και θα διαγραφεί)')
                        ->required()
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search, Customer $record): array => Customer::query()
                            ->where('company_id', $record->company_id)
                            ->whereKeyNot($record->getKey())
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('afm', 'like', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Customer $c): array => [$c->getKey() => '#'.$c->getKey().' — '.$c->name.($c->afm ? ' (ΑΦΜ '.$c->afm.')' : '')])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Customer::query()->whereKey($value)->value('name')),

                    Placeholder::make('merge_preview')
                        ->label('Τι θα μεταφερθεί')
                        ->visible(fn (callable $get): bool => filled($get('drop_id')))
                        ->content(function (callable $get, Customer $record): string {
                            $drop = Customer::query()
                                ->where('company_id', $record->company_id)
                                ->whereKey($get('drop_id'))
                                ->first();
                            if ($drop === null) {
                                return 'Δεν βρέθηκε ο πελάτης.';
                            }

                            try {
                                $preview = app(MergeCustomers::class)->preview($record, $drop);
                            } catch (\RuntimeException $e) {
                                return '⚠ '.$e->getMessage();
                            }

                            $lines = ['Μεταφέρονται: '.$preview->movesLabel().'.'];
                            foreach ($preview->differences as $label => $pair) {
                                $lines[] = '• '.$label.': κρατάμε «'.($pair['keep'] ?? '—').'», ο άλλος είχε «'.($pair['drop'] ?? '—').'» (θα γραφτεί στις σημειώσεις)';
                            }

                            return implode("\n", $lines);
                        }),
                ])
                ->action(function (Customer $record, array $data): void {
                    $drop = Customer::query()
                        ->where('company_id', $record->company_id)
                        ->whereKey($data['drop_id'] ?? 0)
                        ->first();
                    if ($drop === null) {
                        Notification::make()->title('Δεν βρέθηκε ο πελάτης προς συγχώνευση.')->danger()->send();

                        return;
                    }

                    try {
                        $result = app(MergeCustomers::class)($record, $drop);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Η συγχώνευση δεν έγινε')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Συγχωνεύτηκε ο #'.$result->dropId.' «'.$result->dropName.'»')
                        ->body('Μεταφέρθηκαν: '.$result->movesLabel().'. Τα στοιχεία που διέφεραν είναι στις σημειώσεις.')
                        ->success()
                        ->send();

                    // The merge may have ADOPTED the loser's identity keys, but it
                    // writes through its OWN locked instance — this page's record
                    // is stale, and a plain Save would write the old (empty)
                    // values straight back. Re-read, then refill the form.
                    $record->refresh();
                    $this->refreshFormData(['name', 'legacy_id', 'whmcs_client_id']);
                }),

            DeleteAction::make(),
        ];
    }
}
