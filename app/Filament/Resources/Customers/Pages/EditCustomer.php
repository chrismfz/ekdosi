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
                    TextInput::make('search')
                        ->label('Search WHMCS')
                        ->placeholder('Name, email, or company name')
                        ->live(onBlur: true)
                        ->helperText('Hit Tab to search. Results appear below.'),

                    Select::make('whmcs_client_id')
                        ->label('Picked candidate')
                        ->options(function (callable $get) use ($record): array {
                            $needle = trim((string) $get('search'));
                            if ($needle === '') {
                                // Default to the existing link if any,
                                // so an operator opening the modal
                                // sees the current value preselected.
                                return $record->whmcs_client_id
                                    ? [$record->whmcs_client_id => '#'.$record->whmcs_client_id.' (current link)']
                                    : [];
                            }
                            try {
                                $client = app(WhmcsClientFactory::class)->for($record->company);
                                $hits = $client->searchClients($needle, limit: 25);
                            } catch (WhmcsApiException $e) {
                                // Surfacing the error here would
                                // require Filament livewire wiring;
                                // simplest is to return an empty list
                                // + log + let the operator click
                                // Test Connection on the Company
                                // form to diagnose.
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
                        ->default(fn () => $record->whmcs_client_id)
                        ->searchable()
                        ->helperText('Pick a row, or leave blank + click Save to UNLINK.'),

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
