<?php

namespace App\Filament\Resources\Companies\Actions;

use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Services\Backup\CompanyBackupRunner;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyDataWiper;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Filament glue for the per-company backup (docs/company-portability-plan.md,
 * Phase 1 UI). Thin wrappers over CompanyExporter/CompanyImporter so the panel
 * offers the same export/restore the artisan commands do — download a settings
 * .zip, and upload-to-restore (dry-run preview → execute). Admin-only via the
 * Company resource (panel-global, super_admin).
 */
class CompanyBackupActions
{
    /** Per-row: build the settings+setup bundle and stream it as a download. */
    public static function export(): Action
    {
        return Action::make('export_settings')
            ->label('Εξαγωγή ρυθμίσεων')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->modalHeading('Εξαγωγή ρυθμίσεων εταιρίας')
            ->modalDescription('Κατεβάζει .zip με ρυθμίσεις + setup. Τα μυστικά κρυπτογραφούνται με το συνθηματικό — κράτησέ το, χρειάζεται για επαναφορά.')
            ->modalSubmitActionLabel('Εξαγωγή')
            ->schema([
                TextInput::make('passphrase')
                    ->label('Συνθηματικό κρυπτογράφησης')
                    ->password()->revealable()->required()->minLength(4),
                Toggle::make('full')
                    ->label('Πλήρες αντίγραφο (με δεδομένα: πελάτες/παραστατικά/πληρωμές…)')
                    ->helperText('Κλειστό = μόνο ρυθμίσεις + setup.')
                    ->default(false),
            ])
            ->action(function (array $data, Company $record) {
                $bundle = app(CompanyExporter::class)->build(
                    $record, 'passphrase', (string) $data['passphrase'], (bool) ($data['full'] ?? false)
                );
                $suffix = ($data['full'] ?? false) ? 'full' : 'settings';
                $path = storage_path('app/exports/'.$record->slug.'-'.$suffix.'-'.now()->format('Ymd-His').'.zip');
                app(BundleArchive::class)->write($path, $bundle);

                return response()->download($path, basename($path))->deleteFileAfterSend();
            });
    }

    /** Per-row: upload a bundle and restore INTO this existing company. */
    public static function importInto(): Action
    {
        return Action::make('import_settings')
            ->label('Εισαγωγή ρυθμίσεων')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->modalHeading('Εισαγωγή ρυθμίσεων σε αυτή την εταιρία')
            ->modalDescription('Ανέβασε ένα .zip. Χωρίς «Εκτέλεση» δείχνει μόνο προεπισκόπηση. Idempotent upsert — δεν σβήνει υπάρχουσες γραμμές.')
            ->modalSubmitActionLabel('Συνέχεια')
            ->schema(self::importFields())
            ->action(fn (array $data, Company $record) => self::runImport($data, ['into' => $record->slug]));
    }

    /** Toolbar: upload a bundle and create a NEW company from it. */
    public static function importNew(): Action
    {
        return Action::make('import_new_company')
            ->label('Εισαγωγή εταιρίας από αρχείο')
            ->icon('heroicon-o-building-office-2')
            ->color('primary')
            ->modalHeading('Εισαγωγή νέας εταιρίας από αρχείο')
            ->modalDescription('Δημιουργεί νέα εταιρία από το .zip (αν δεν υπάρχει ήδη το slug).')
            ->modalSubmitActionLabel('Συνέχεια')
            ->schema(self::importFields())
            ->action(fn (array $data) => self::runImport($data, ['new' => true]));
    }

    /** Per-row: configure the automated-backup policy (Phase 4). */
    public static function scheduleSettings(): Action
    {
        return Action::make('backup_schedule')
            ->label('Αυτόματα αντίγραφα')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading('Ρυθμίσεις αυτόματων αντιγράφων')
            ->modalDescription('Πρόγραμμα + κρυπτογράφηση + διατήρηση. Προς το παρόν ο προορισμός είναι Τοπικά (λήψη από το panel)· SFTP/FTP/S3 έρχονται.')
            ->modalSubmitActionLabel('Αποθήκευση')
            // Drive the form straight off the model's attributes (form components
            // ignore keys without a matching field) so a new setting column added
            // in Slice 4b can't be silently dropped from the edit form.
            ->fillForm(fn (Company $record) => $record->backupSetting?->attributesToArray()
                ?? ['frequency' => 'off', 'bucket' => 'settings_setup', 'secrets_mode' => 'passphrase', 'run_at_time' => '02:00', 'retention_keep' => 7])
            ->schema([
                Toggle::make('enabled')->label('Ενεργό')->default(false),
                Select::make('frequency')->label('Συχνότητα')
                    ->options(['off' => 'Ανενεργό', 'daily' => 'Καθημερινά', 'weekly' => 'Εβδομαδιαία', 'monthly' => 'Μηνιαία'])
                    ->default('off')->required(),
                TextInput::make('run_at_time')->label('Ώρα (HH:MM)')->default('02:00')
                    ->rule('date_format:H:i')->required(),
                // Only two distinct behaviours exist: the exporter ALWAYS dumps
                // setup; --full adds transactional. (A settings-only-without-setup
                // bundle isn't implemented, so it's not offered — see plan doc.)
                Select::make('bucket')->label('Περιεχόμενο')
                    ->options(['settings_setup' => 'Ρυθμίσεις + setup', 'full' => 'Πλήρες (με δεδομένα)'])
                    ->default('settings_setup')->required(),
                Select::make('secrets_mode')->label('Μυστικά')
                    ->options(['passphrase' => 'Κρυπτογραφημένα (συνθηματικό)', 'raw' => 'Χωρίς κρυπτογράφηση (μόνο τοπικά!)'])
                    ->default('passphrase')->live()->required(),
                TextInput::make('passphrase')->label('Συνθηματικό')
                    ->password()->revealable()
                    ->requiredIf('secrets_mode', 'passphrase')
                    ->helperText('Χρειάζεται για επαναφορά — κράτησέ το ασφαλές.'),
                TextInput::make('retention_keep')->label('Διατήρηση (πλήθος)')->numeric()->default(7)->minValue(0),
                TextInput::make('retention_days')->label('…ή ημέρες (προαιρετικό)')->numeric()->nullable()->minValue(1),
            ])
            ->action(function (array $data, Company $record): void {
                CompanyBackupSetting::updateOrCreate(['company_id' => $record->id], $data);
                Notification::make()->title('Αποθηκεύτηκαν οι ρυθμίσεις αντιγράφων')->success()->send();
            });
    }

    /** Per-row: run a backup right now via the saved policy (writes a run row). */
    public static function runNow(): Action
    {
        return Action::make('backup_run_now')
            ->label('Αντίγραφο τώρα')
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Τρέχει ένα αντίγραφο τώρα με τις αποθηκευμένες ρυθμίσεις (πρόγραμμα/μυστικά/προορισμοί).')
            ->action(function (Company $record): void {
                $settings = $record->backupSetting;
                if ($settings === null) {
                    Notification::make()->title('Ρύθμισε πρώτα τα «Αυτόματα αντίγραφα»')->warning()
                        ->body('Χρειάζεται μια πολιτική (συνθηματικό / περιεχόμενο / διατήρηση) πριν το χειροκίνητο τρέξιμο.')->send();

                    return;
                }

                $run = app(CompanyBackupRunner::class)->run($record, $settings, 'manual');

                Notification::make()->title('Αντίγραφο: '.$run->status)
                    ->body($run->message ?? ('Μέγεθος: '.number_format(((int) $run->bytes) / 1024, 1).' KB'))
                    ->color($run->statusColor())
                    ->send();
            });
    }

    /** Per-row: wipe transactional data (keep settings+setup). Dry-run unless «Εκτέλεση». */
    public static function wipe(): Action
    {
        return Action::make('wipe_transactional')
            ->label('Διαγραφή δεδομένων')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading('Διαγραφή συναλλακτικών δεδομένων')
            ->modalDescription('Σβήνει παραστατικά/πληρωμές/πελάτες κ.λπ. ΚΡΑΤΑΕΙ ρυθμίσεις + setup. Χωρίς «Εκτέλεση» δείχνει μόνο προεπισκόπηση. ⚠ Πάρε backup πρώτα.')
            ->modalSubmitActionLabel('Συνέχεια')
            ->schema([
                Toggle::make('keep_parties')
                    ->label('Κράτα πελάτες/προμηθευτές/προϊόντα')->default(false),
                Toggle::make('reset_counter')
                    ->label('Μηδενισμός μετρητή ΑΑ (invcount → 1)')
                    ->helperText('⚠ Μόνο πριν από Firebird import — αλλιώς το επόμενο ΑΑ μπορεί να συγκρουστεί με ήδη υποβλημένο στην ΑΑΔΕ.')
                    ->default(false),
                Toggle::make('force')
                    ->label('Διαγραφή ακόμη κι αν υπάρχουν υποβλημένα στην ΑΑΔΕ (VALID)')->default(false),
                Toggle::make('execute')
                    ->label('Εκτέλεση (αλλιώς προεπισκόπηση)')->default(false),
            ])
            ->action(function (array $data, Company $record): void {
                $wiper = app(CompanyDataWiper::class);
                $keepParties = (bool) ($data['keep_parties'] ?? false);
                $plan = $wiper->plan($record, $keepParties);
                $filed = $wiper->filedAtAadeCount($record);

                if ($plan === []) {
                    Notification::make()->title('Δεν υπάρχουν δεδομένα για διαγραφή')->info()->send();

                    return;
                }

                $summary = collect($plan)->map(fn ($n, $t) => "{$t}: {$n}")->implode("\n");

                if (! ($data['execute'] ?? false)) {
                    Notification::make()->title('Προεπισκόπηση — τίποτα δεν διαγράφηκε')
                        ->warning()->body($summary)->send();

                    return;
                }

                if ($filed > 0 && ! ($data['force'] ?? false)) {
                    Notification::make()->title('Διακοπή')->danger()
                        ->body("{$filed} παραστατικά είναι υποβλημένα στην ΑΑΔΕ (VALID). Ενεργοποίησε «Διαγραφή ακόμη κι αν…» για να συνεχίσεις.")
                        ->send();

                    return;
                }

                $deleted = $wiper->wipe($record, $keepParties, (bool) ($data['reset_counter'] ?? false), (bool) ($data['force'] ?? false));
                Notification::make()->title('Η διαγραφή ολοκληρώθηκε')->success()
                    ->body('Διαγράφηκαν '.array_sum($deleted).' γραμμές σε '.count($deleted).' πίνακες.')
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    private static function importFields(): array
    {
        return [
            FileUpload::make('bundle')
                ->label('Αρχείο αντιγράφου (.zip)')
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                ->storeFiles(false)
                ->required(),
            TextInput::make('passphrase')
                ->label('Συνθηματικό αρχείου')
                ->password()->revealable()
                ->helperText('Άφησέ το κενό αν το αρχείο εξήχθη με --raw.'),
            Toggle::make('execute')
                ->label('Εκτέλεση (αλλιώς προεπισκόπηση)')
                ->default(false),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array{into?:string, new?:bool}  $target
     */
    private static function runImport(array $data, array $target): void
    {
        $upload = is_array($data['bundle'] ?? null) ? reset($data['bundle']) : ($data['bundle'] ?? null);
        if (! $upload) {
            Notification::make()->title('Λείπει το αρχείο')->danger()->send();

            return;
        }

        try {
            $bundle = app(BundleArchive::class)->read($upload->getRealPath());
            $mode = $bundle['secrets']['mode'] ?? 'passphrase';
            $passphrase = $mode === 'raw' ? null : (((string) ($data['passphrase'] ?? '')) ?: null);

            $summary = app(CompanyImporter::class)->run($bundle, $target + [
                'execute' => (bool) ($data['execute'] ?? false),
                'passphrase' => $passphrase,
            ]);
        } catch (Throwable $e) {
            Notification::make()->title('Αποτυχία εισαγωγής')->danger()->body($e->getMessage())->send();

            return;
        }

        $lines = [];
        foreach ($summary['tables'] as $table => $counts) {
            $lines[] = $table.': +'.($counts['insert'] ?? 0).' / ~'.($counts['update'] ?? 0);
        }

        $notification = Notification::make()
            ->title($summary['dry_run'] ? 'Προεπισκόπηση — τίποτα δεν γράφτηκε' : 'Η εισαγωγή ολοκληρώθηκε')
            ->body(($summary['company'] === 'create' ? 'Δημιουργία' : 'Ενημέρωση')
                .' «'.$summary['slug'].'»'.($lines === [] ? '' : "\n".implode("\n", $lines)));

        $summary['dry_run'] ? $notification->warning() : $notification->success();
        $notification->send();
    }
}
