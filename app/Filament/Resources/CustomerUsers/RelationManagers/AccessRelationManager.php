<?php

namespace App\Filament\Resources\CustomerUsers\RelationManagers;

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\Scopes\CompanyScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Operator surface: which customers (in which company) this portal login may
 * see — «προσθέτει/αφαιρεί linked users ανά εταιρία (ΑΦΜ)». Each grant is
 * audited (granted_by/granted_at) and revoked softly. Inherits the parent
 * resource's super-admin-only gate.
 *
 * NOTE: nothing reads these grants yet (the portal documents view is a later
 * slice); this is the management surface + audited data.
 */
class AccessRelationManager extends RelationManager
{
    protected static string $relationship = 'accessGrants';

    protected static ?string $title = 'Πρόσβαση σε πελάτες';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Πελάτης')
                ->required()
                ->searchable()
                // Grants are CROSS-COMPANY, so search ALL tenants' customers —
                // drop the ambient CompanyScope (otherwise only the panel's active
                // tenant's customers show, defeating the whole purpose).
                ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('afm', 'like', "%{$search}%"))
                    // Don't offer a customer this login already has a grant for.
                    ->whereNotIn('id', $this->getOwnerRecord()->accessGrants()->pluck('customer_id'))
                    ->with('company:id,name')
                    ->limit(30)
                    ->get()
                    ->mapWithKeys(fn (Customer $c): array => [$c->id => static::customerLabel($c)])
                    ->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($c = Customer::withoutGlobalScope(CompanyScope::class)->with('company:id,name')->find($value)) ? static::customerLabel($c) : null),
            Select::make('role')
                ->label('Ρόλος')
                ->required()
                ->default(CustomerUserAccess::ROLE_OWNER)
                ->native(false)
                ->options([
                    CustomerUserAccess::ROLE_OWNER => 'Ιδιοκτήτης (ο ίδιος ο πελάτης)',
                    CustomerUserAccess::ROLE_RESELLER => 'Μεταπωλητής (τον έφερε)',
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->description(fn (CustomerUserAccess $r): ?string => $r->customer?->afm ? 'ΑΦΜ '.$r->customer->afm : null)
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label('Εταιρία')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('role')
                    ->label('Ρόλος')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CustomerUserAccess::ROLE_RESELLER ? 'Μεταπωλητής' : 'Ιδιοκτήτης')
                    ->color(fn (string $state): string => $state === CustomerUserAccess::ROLE_RESELLER ? 'warning' : 'success'),
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->state(fn (CustomerUserAccess $r): string => $r->isActive() ? 'Ενεργή' : 'Ανακλήθηκε')
                    ->color(fn (CustomerUserAccess $r): string => $r->isActive() ? 'success' : 'danger'),
                TextColumn::make('grantedBy.name')
                    ->label('Από')
                    ->placeholder('Σύστημα')
                    ->toggleable(),
                TextColumn::make('granted_at')
                    ->label('Ημ/νία')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Νέα πρόσβαση')
                    ->mutateDataUsing(function (array $data): array {
                        // company_id follows the chosen customer (customers are
                        // per-company). Resolve cross-tenant (no CompanyScope) and
                        // fail friendly rather than inserting a null company_id.
                        $customer = Customer::withoutGlobalScope(CompanyScope::class)->find($data['customer_id']);
                        if ($customer === null) {
                            Notification::make()->title('Ο πελάτης δεν βρέθηκε — δοκίμασε ξανά.')->danger()->send();

                            throw new Halt;
                        }
                        $data['company_id'] = $customer->company_id;
                        $data['granted_by'] = Auth::id();
                        $data['granted_at'] = now();

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Ανάκληση')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (CustomerUserAccess $r): bool => $r->isActive())
                    ->requiresConfirmation()
                    ->action(fn (CustomerUserAccess $r) => $r->forceFill(['revoked_at' => now()])->save()),
                Action::make('reactivate')
                    ->label('Επαναφορά')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerUserAccess $r): bool => ! $r->isActive())
                    ->requiresConfirmation()
                    ->action(fn (CustomerUserAccess $r) => $r->forceFill(['revoked_at' => null])->save()),
                DeleteAction::make(),
            ]);
    }

    private static function customerLabel(Customer $c): string
    {
        $afm = $c->afm ? ' · ΑΦΜ '.$c->afm : '';
        $company = $c->company?->name ? ' — '.$c->company->name : '';

        return $c->name.$afm.$company;
    }
}
