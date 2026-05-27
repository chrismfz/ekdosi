<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Whmcs\WhmcsClientFactory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
                            // Called when the modal first renders and
                            // the Select needs to display the CURRENT
                            // value. Hit the WHMCS API once to fetch
                            // a sensible label. If WHMCS is
                            // unavailable, fall back to the bare id.
                            if (! $value) {
                                return null;
                            }
                            try {
                                $client = app(WhmcsClientFactory::class)->for($record->company);
                                $hit = $client->getClient((int) $value);
                                if ($hit === null) {
                                    return '#'.$value.' (not found in WHMCS)';
                                }
                                $name = trim((string) ($hit['companyname'] ?? ''));
                                if ($name === '') {
                                    $name = trim(($hit['firstname'] ?? '').' '.($hit['lastname'] ?? ''));
                                }
                                return '#'.$value.' — '.($name ?: '(no name)');
                            } catch (WhmcsApiException) {
                                return '#'.$value.' (WHMCS unreachable)';
                            }
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

            DeleteAction::make(),
        ];
    }
}
