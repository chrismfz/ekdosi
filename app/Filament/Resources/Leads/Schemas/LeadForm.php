<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Support\Tags\TagControls;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadMatch;
use App\Services\Leads\LeadMatcher;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Lead form. Only the name is required — «δεν μας πειράζει που δεν έχουμε
 * ΑΦΜ». Field names mirror `customers` (afm/address1/city/postcode/country/
 * occupation) so conversion is a 1:1 copy.
 *
 * The dedupe banner («Είναι ήδη πελάτης» / «Υπάρχει ήδη ως lead») re-renders
 * on blur of ΑΦΜ / email / phones via LeadMatcher — the whole point of the
 * module («να μην ξαναζαλίζουμε κόσμο»).
 */
class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Στοιχεία')
                    ->description('Ό,τι ξέρουμε — μόνο η επωνυμία είναι υποχρεωτική.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Επωνυμία / Όνομα')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('contact_person')
                            ->label('Με ποιον μιλάμε')
                            ->maxLength(255),

                        TextInput::make('occupation')
                            ->label('Κλάδος / Δραστηριότητα')
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label('Τηλέφωνο')
                            ->tel()
                            ->maxLength(60)
                            ->live(onBlur: true),

                        TextInput::make('mobile')
                            ->label('Κινητό')
                            ->tel()
                            ->maxLength(60)
                            ->live(onBlur: true),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->live(onBlur: true),

                        TextInput::make('website')
                            ->label('Ιστοσελίδα')
                            ->maxLength(255),

                        TextInput::make('afm')
                            ->label('ΑΦΜ')
                            ->maxLength(20)
                            ->live(onBlur: true),

                        TextInput::make('country')
                            ->label('Χώρα (ISO-2)')
                            ->default('GR')
                            ->maxLength(2),

                        TextInput::make('address1')
                            ->label('Διεύθυνση')
                            ->maxLength(255),

                        TextInput::make('city')
                            ->label('Πόλη')
                            ->maxLength(120),

                        TextInput::make('postcode')
                            ->label('Τ.Κ.')
                            ->maxLength(20),

                        Placeholder::make('dedupe')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(fn (callable $get, ?Lead $record): HtmlString => self::dedupeBanner($get, $record)),
                    ]),

                Section::make('Παρακολούθηση')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label('Κατάσταση')
                            ->options(LeadStatus::options())
                            ->default(LeadStatus::New->value)
                            ->required()
                            ->live()
                            // Won is only ever set by the conversion action; keep
                            // the stored value visible/unchanged on a converted lead.
                            ->disabled(fn (?Lead $record): bool => $record?->status === LeadStatus::Won)
                            ->dehydrated(fn (?Lead $record): bool => $record?->status !== LeadStatus::Won),

                        Select::make('assigned_user_id')
                            ->label('Χειριστής')
                            ->options(fn (): array => self::userOptions())
                            ->default(fn () => auth()->id())
                            ->searchable()
                            ->preload(),

                        Select::make('source')
                            ->label('Πηγή')
                            ->options(LeadSource::options()),

                        Select::make('referred_by_customer_id')
                            ->label('Σύσταση από πελάτη')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->toArray())
                            ->getOptionLabelUsing(fn ($value): ?string => Customer::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->find($value)?->name),

                        DateTimePicker::make('next_action_at')
                            ->label('Επόμενο βήμα')
                            ->seconds(false)
                            ->helperText('Πότε να το ξαναδούμε — εμφανίζεται κόκκινο στη λίστα όταν περάσει.'),

                        TextInput::make('lost_reason')
                            ->label('Λόγος')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => self::statusRequiresReason($get('status')))
                            ->required(fn (callable $get): bool => self::statusRequiresReason($get('status'))),

                        Textarea::make('notes')
                            ->label('Περιγραφή')
                            ->rows(3)
                            ->helperText('Τι θέλει, τι έχει σήμερα, γενική εικόνα. Οι επαφές πάνε στο Χρονολόγιο.')
                            ->columnSpanFull(),

                        TagControls::field()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function statusRequiresReason(mixed $status): bool
    {
        $enum = $status instanceof LeadStatus ? $status : LeadStatus::tryFrom((string) $status);

        return $enum?->requiresReason() ?? false;
    }

    /**
     * Users attached to the current tenant + the current user (a super_admin
     * may not be on the pivot yet still logs the contact).
     *
     * @return array<int, string>
     */
    private static function userOptions(): array
    {
        $tenant = Filament::getTenant();
        $options = $tenant
            ? $tenant->users()->orderBy('name')->pluck('users.name', 'users.id')->all()
            : [];

        $me = auth()->user();
        if ($me instanceof User && ! isset($options[$me->getKey()])) {
            $options[$me->getKey()] = $me->name;
        }

        return $options;
    }

    /**
     * The «already known» warning. Inline styles on purpose — the panel ships
     * no Tailwind utility layer (CLAUDE.md «No-build CSS»).
     */
    private static function dedupeBanner(callable $get, ?Lead $record): HtmlString
    {
        $tenantId = Filament::getTenant()?->getKey();
        if ($tenantId === null) {
            return new HtmlString('');
        }

        $match = app(LeadMatcher::class)->find(
            (int) $tenantId,
            $get('afm'),
            $get('email'),
            [$get('phone'), $get('mobile')],
            $record?->getKey(),
        );

        if ($match->isEmpty()) {
            return new HtmlString('');
        }

        return new HtmlString(self::renderMatch($match));
    }

    public static function renderMatch(LeadMatch $match): string
    {
        $danger = $match->hasDoNotContact();
        $border = $danger ? '#dc2626' : '#d97706';
        $bg = $danger ? 'rgba(220,38,38,.08)' : 'rgba(217,119,6,.08)';

        $lines = [];

        foreach ($match->customers as $customer) {
            $lines[] = '<li><strong>Είναι ήδη πελάτης:</strong> '.e($customer->name)
                .($customer->afm ? ' (ΑΦΜ '.e($customer->afm).')' : '').'</li>';
        }

        foreach ($match->leads as $lead) {
            $bits = [$lead->status?->getLabel() ?? '—'];
            if ($lead->lost_reason) {
                $bits[] = 'λόγος: '.$lead->lost_reason;
            }
            if ($lead->assignedTo) {
                $bits[] = 'χειριστής: '.$lead->assignedTo->name;
            }
            $bits[] = 'ενημέρωση '.$lead->updated_at?->format('d/m/Y');
            if ($lead->trashed()) {
                $bits[] = 'διαγραμμένο';
            }

            $lines[] = '<li><strong>Υπάρχει ήδη ως lead:</strong> '.e($lead->name)
                .' — '.e(implode(' · ', $bits)).'</li>';
        }

        $title = $danger
            ? '⛔ Μας έχουν ζητήσει να ΜΗΝ τους ξαναενοχλήσουμε.'
            : '⚠ Τον ξέρουμε ήδη — έλεγξε πριν επικοινωνήσεις.';

        return '<div style="border-left:4px solid '.$border.';background:'.$bg.';padding:.6rem .9rem;border-radius:.375rem;font-size:.875rem;line-height:1.5">'
            .'<div style="font-weight:600;margin-bottom:.25rem">'.$title.'</div>'
            .'<ul style="margin:0;padding-left:1.1rem">'.implode('', $lines).'</ul>'
            .'</div>';
    }
}
