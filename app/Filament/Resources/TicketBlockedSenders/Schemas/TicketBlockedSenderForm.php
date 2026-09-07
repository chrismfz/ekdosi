<?php

namespace App\Filament\Resources\TicketBlockedSenders\Schemas;

use App\Models\TicketBlockedSender;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TicketBlockedSenderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('pattern')
                    ->label('Email ή domain')
                    ->required()
                    ->maxLength(191)
                    ->helperText('π.χ. spammer@example.com (μία διεύθυνση) ή example.com (ΟΛΟ το domain — προσοχή, αποκλείει κάθε αποστολέα σε αυτό).')
                    // Store normalised (lowercased, no leading «@») — same as the model mutator.
                    ->dehydrateStateUsing(fn (?string $state): string => TicketBlockedSender::normalizePattern($state))
                    // Tenant-scoped uniqueness on the NORMALISED value (Filament's ->unique
                    // would check the raw input against the whole table, cross-tenant).
                    ->rule(static function (): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail): void {
                            $pattern = TicketBlockedSender::normalizePattern(is_string($value) ? $value : '');
                            if ($pattern === '') {
                                $fail('Δώσε έγκυρο email ή domain.');

                                return;
                            }
                            $exists = TicketBlockedSender::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->where('pattern', $pattern)
                                ->exists();
                            if ($exists) {
                                $fail('Ο αποστολέας είναι ήδη αποκλεισμένος.');
                            }
                        };
                    }),
                TextInput::make('reason')
                    ->label('Αιτιολογία (προαιρετικά)')
                    ->maxLength(191),
            ]);
    }
}
