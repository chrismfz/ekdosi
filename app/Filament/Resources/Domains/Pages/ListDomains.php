<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Enums\DomainStatus;
use App\Filament\BaseListRecords;
use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarFactory;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDomains extends BaseListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkAvailability')
                ->label('Έλεγχος διαθεσιμότητας')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->schema([
                    TextInput::make('fqdn')
                        ->label('Domain')
                        ->required()
                        ->placeholder('example.gr')
                        ->helperText('Δρομολογείται μέσω του TLD του («TLDs & τιμές»).'),
                ])
                ->action(function (array $data): void {
                    $fqdn = mb_strtolower(trim((string) $data['fqdn']));
                    $extension = str_contains($fqdn, '.') ? mb_substr($fqdn, mb_strpos($fqdn, '.') + 1) : '';

                    // §2 routing: the TLD row says which connection answers.
                    $connection = DomainTld::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('tld', $extension)
                        ->first()?->registrarConnection;
                    if ($connection === null) {
                        Notification::make()
                            ->title('Χωρίς δρομολόγηση registrar.')
                            ->body('Το .'.$extension.' δεν έχει σύνδεση registrar στα «TLDs & τιμές».')
                            ->warning()->send();

                        return;
                    }

                    $factory = app(DomainRegistrarFactory::class);
                    try {
                        $result = $factory->for($connection)->checkAvailability($fqdn, $factory->credentialsFor($connection));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Ο έλεγχος απέτυχε.')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $result->available
                        ? Notification::make()->title($result->fqdn.' — διαθέσιμο ✅')->success()->send()
                        : Notification::make()->title($result->fqdn.' — κατειλημμένο')->body((string) ($result->reason ?? ''))->warning()->send();
                }),
            CreateAction::make()->label('Καταχώρηση domain'),
        ];
    }

    /**
     * Work tabs. NOTE: the modifyQueryUsing closure parameter MUST be named
     * `$query` (Filament injects by argument name — the ListTickets lesson).
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Ενεργά')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DomainStatus::Active->value)),
            'expiring' => Tab::make('Λήγουν σύντομα')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->expiringSoon())
                ->badge(fn (): int => Domain::query()->expiringSoon()->count())
                ->badgeColor('warning'),
            'unassigned' => Tab::make('Χωρίς πελάτη')
                // Only ASSIGNABLE rows — a terminal-status stray would ring a
                // worklist whose action always refuses it.
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->assignable())
                ->badge(fn (): int => Domain::query()->assignable()->count())
                ->badgeColor('warning'),
            'all' => Tab::make('Όλα'),
        ];
    }
}
