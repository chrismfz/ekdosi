<?php

namespace App\Filament\Resources\Companies\Actions;

use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
use App\Services\Backup\CompanyBackupRunner;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyDataWiper;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use App\Services\Portability\CsvEntityExporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Filament glue for the per-company backup (FEATURES.md,
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
            ->modalDescription('Κατεβάζει .zip με ρυθμίσεις + setup. Διάλεξε αν τα μυστικά (κλειδιά myDATA/GSIS/SMTP/WHMCS) θα κρυπτογραφηθούν με συνθηματικό ή θα γραφτούν χωρίς κρυπτογράφηση (για τοπικό αρχείο).')
            ->modalSubmitActionLabel('Εξαγωγή')
            ->schema([
                // Passphrase is OPTIONAL: «raw» (no encryption) is a first-class
                // choice for a local download, so an export never *requires* a
                // password — the step toward working without APP_KEY/encryption.
                Select::make('secrets_mode')
                    ->label('Μυστικά')
                    ->options([
                        'passphrase' => 'Κρυπτογραφημένα (με συνθηματικό)',
                        'raw' => 'Χωρίς κρυπτογράφηση (μόνο τοπικά!)',
                    ])
                    ->default('passphrase')->live()->required(),
                TextInput::make('passphrase')
                    ->label('Συνθηματικό κρυπτογράφησης')
                    ->password()->revealable()
                    ->visible(fn (Get $get) => $get('secrets_mode') === 'passphrase')
                    ->requiredIf('secrets_mode', 'passphrase')->minLength(4)
                    ->helperText('Χρειάζεται για επαναφορά — κράτησέ το ασφαλές.'),
                Placeholder::make('raw_warning')
                    ->label('')
                    ->content('⚠ Τα μυστικά θα γραφτούν σε ΚΑΘΑΡΟ ΚΕΙΜΕΝΟ μέσα στο .zip. Κράτησέ το αρχείο μόνο σε ασφαλές, τοπικό σημείο.')
                    ->visible(fn (Get $get) => $get('secrets_mode') === 'raw'),
                Toggle::make('full')
                    ->label('Πλήρες αντίγραφο (με δεδομένα: πελάτες/παραστατικά/πληρωμές…)')
                    ->helperText('Κλειστό = μόνο ρυθμίσεις + setup.')
                    ->default(false),
            ])
            ->action(function (array $data, Company $record) {
                $mode = ($data['secrets_mode'] ?? 'passphrase') === 'raw' ? 'raw' : 'passphrase';
                $passphrase = $mode === 'raw' ? null : (string) ($data['passphrase'] ?? '');
                $bundle = app(CompanyExporter::class)->build(
                    $record, $mode, $passphrase, (bool) ($data['full'] ?? false)
                );
                $suffix = ($data['full'] ?? false) ? 'full' : 'settings';
                $path = storage_path('app/exports/'.$record->slug.'-'.$suffix.'-'.now()->format('Ymd-His').'.zip');
                app(BundleArchive::class)->write($path, $bundle);

                return response()->download($path, basename($path))->deleteFileAfterSend();
            });
    }

    /**
     * Per-row: Portability Phase 3 — pick entities and download a .zip of plain
     * per-entity CSVs (open in Excel / hand to an accountant). NOT a restore
     * bundle (that's export()); tenant-scoped, secret columns redacted.
     */
    public static function exportCsv(): Action
    {
        return Action::make('export_csv')
            ->label('Εξαγωγή CSV')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->modalHeading('Εξαγωγή σε CSV')
            ->modalDescription('Διάλεξε τι να τραβήξεις. Κατεβάζει .zip με ένα CSV ανά entity (ανοίγει σε Excel). Δεν είναι αντίγραφο επαναφοράς — για μεταφορά/λογιστή.')
            ->modalSubmitActionLabel('Εξαγωγή')
            ->schema([
                CheckboxList::make('entities')
                    ->label('Τι να εξαχθεί')
                    ->options(fn () => collect(app(CsvEntityExporter::class)->available())
                        ->mapWithKeys(fn (string $k): array => [$k => CsvEntityExporter::LABELS[$k] ?? $k])
                        ->all())
                    ->default(fn () => app(CsvEntityExporter::class)->available())
                    ->columns(2)
                    ->bulkToggleable()
                    ->required(),
            ])
            ->action(function (array $data, Company $record) {
                $exporter = app(CsvEntityExporter::class);
                $entities = array_values(array_intersect((array) ($data['entities'] ?? []), $exporter->available()));
                if ($entities === []) {
                    Notification::make()->title('Δεν επιλέχθηκε entity')->warning()->send();

                    return null;
                }

                $files = $exporter->export($record, $entities);
                $path = storage_path('app/exports/'.$record->slug.'-csv-'.now()->format('Ymd-His').'.zip');
                $exporter->writeZip($files, $path);

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
            ->modalDescription('Πρόγραμμα + κρυπτογράφηση + διατήρηση + προορισμοί (Τοπικά / SFTP / FTP / S3). Τα «Τοπικά» περιλαμβάνονται πάντα για λήψη από το panel.')
            ->modalSubmitActionLabel('Αποθήκευση')
            // Drive the form straight off the model's attributes (form components
            // ignore keys without a matching field) so a new setting column added
            // in Slice 4b can't be silently dropped from the edit form.
            // passphrase is $hidden (out of toArray) → re-inject it explicitly so
            // the form prefills it (else a save would force re-entry / wipe it).
            ->fillForm(fn (Company $record) => $record->backupSetting
                ? $record->backupSetting->attributesToArray() + ['passphrase' => $record->backupSetting->passphrase]
                : ['frequency' => 'off', 'bucket' => 'full', 'secrets_mode' => 'passphrase', 'run_at_time' => '02:00', 'retention_keep' => 7])
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
                // OPS-5: default to «Πλήρες» — a DR backup that excludes the books
                // (invoices/payments/marks) is a false safety net; «Ρυθμίσεις + setup»
                // stays available for a deliberate config-only bundle.
                Select::make('bucket')->label('Περιεχόμενο')
                    ->options(['settings_setup' => 'Ρυθμίσεις + setup', 'full' => 'Πλήρες (με δεδομένα)'])
                    ->default('full')->required()
                    ->helperText('«Πλήρες» περιλαμβάνει τα βιβλία (τιμολόγια/πληρωμές). «Ρυθμίσεις + setup» ΔΕΝ τα περιλαμβάνει.'),
                Select::make('secrets_mode')->label('Μυστικά')
                    ->options(['passphrase' => 'Κρυπτογραφημένα (συνθηματικό)', 'raw' => 'Χωρίς κρυπτογράφηση (μόνο τοπικά!)'])
                    ->default('passphrase')->live()->required(),
                TextInput::make('passphrase')->label('Συνθηματικό')
                    ->password()->revealable()
                    ->requiredIf('secrets_mode', 'passphrase')
                    ->visible(fn (Get $get) => $get('secrets_mode') === 'passphrase')
                    ->helperText('Χρειάζεται για επαναφορά — κράτησέ το ασφαλές.'),
                // Same plaintext warning as the export action, so «raw» is never a
                // silent choice (the confirm_raw_remote checkbox below covers the
                // remote case; this flags the local-zip plaintext too).
                Placeholder::make('raw_warning')
                    ->label('')
                    ->content('⚠ Με «Χωρίς κρυπτογράφηση» τα μυστικά γράφονται σε ΚΑΘΑΡΟ ΚΕΙΜΕΝΟ μέσα στο αντίγραφο. Κράτησέ το μόνο σε ασφαλές, τοπικό σημείο.')
                    ->visible(fn (Get $get) => $get('secrets_mode') === 'raw'),
                TextInput::make('retention_keep')->label('Διατήρηση (πλήθος)')->numeric()->default(7)->minValue(0),
                TextInput::make('retention_days')->label('…ή ημέρες (προαιρετικό)')->numeric()->nullable()->minValue(1),

                Repeater::make('destinations')
                    ->label('Προορισμοί')
                    ->helperText('Τα «Τοπικά» περιλαμβάνονται πάντα. Πρόσθεσε απομακρυσμένους για αντίγραφο εκτός του VM.')
                    ->addActionLabel('Προσθήκη προορισμού')
                    ->default([])
                    ->columns(2)
                    ->itemLabel(fn (array $state): string => strtoupper((string) ($state['driver'] ?? '—'))
                        .(($state['host'] ?? $state['bucket'] ?? '') !== '' ? ' · '.($state['host'] ?? $state['bucket']) : ''))
                    ->schema(self::destinationFields()),

                // The escape hatch: raw secrets to a REMOTE target is allowed, but
                // only behind an explicit acknowledgement (decision: not forced
                // passphrase). Hidden unless it actually applies.
                Checkbox::make('confirm_raw_remote')
                    ->label('Καταλαβαίνω ότι τα μυστικά θα φύγουν ΧΩΡΙΣ κρυπτογράφηση σε απομακρυσμένο προορισμό.')
                    ->visible(fn (Get $get) => $get('secrets_mode') === 'raw' && self::formHasRemote($get('destinations')))
                    ->accepted()
                    ->dehydrated(false)
                    ->validationMessages(['accepted' => 'Πρέπει να επιβεβαιώσεις την αποστολή χωρίς κρυπτογράφηση, ή να επιλέξεις «Κρυπτογραφημένα».']),
            ])
            ->action(function (array $data, Company $record): void {
                CompanyBackupSetting::updateOrCreate(['company_id' => $record->id], $data);
                Notification::make()->title('Αποθηκεύτηκαν οι ρυθμίσεις αντιγράφων')->success()->send();
            });
    }

    /**
     * The per-destination config fields (driver-conditional). Stored FLAT in each
     * repeater item; the matching BackupDestination reads the keys it needs.
     *
     * @return list<Component>
     */
    private static function destinationFields(): array
    {
        $isSftpOrFtp = fn (Get $get) => in_array($get('driver'), ['sftp', 'ftp'], true);
        $is = fn (string $driver) => fn (Get $get) => $get('driver') === $driver;

        return [
            Select::make('driver')->label('Τύπος')
                ->options(['local' => 'Τοπικά', 'sftp' => 'SFTP', 'ftp' => 'FTP / FTPS', 's3' => 'S3 / συμβατό'])
                ->default('sftp')->required()->live()->columnSpanFull(),

            TextInput::make('host')->label('Host')->visible($isSftpOrFtp)->requiredIf('driver', ['sftp', 'ftp']),
            TextInput::make('port')->label('Port')->numeric()->visible($isSftpOrFtp)
                ->placeholder(fn (Get $get) => $get('driver') === 'ftp' ? '21' : '22'),
            TextInput::make('username')->label('Χρήστης')->visible($isSftpOrFtp),
            TextInput::make('password')->label('Κωδικός')->password()->revealable()->visible($isSftpOrFtp),
            Textarea::make('private_key')->label('Private key (SFTP, εναλλακτικά του κωδικού)')->rows(3)
                ->visible($is('sftp'))->columnSpanFull(),
            TextInput::make('key_passphrase')->label('Passphrase ιδιωτικού κλειδιού')->password()->visible($is('sftp')),
            Toggle::make('ssl')->label('FTPS (SSL)')->visible($is('ftp')),
            Toggle::make('passive')->label('Passive mode')->default(true)->visible($is('ftp')),

            TextInput::make('bucket')->label('Bucket')->visible($is('s3'))->requiredIf('driver', 's3'),
            TextInput::make('region')->label('Region')->placeholder('us-east-1')->visible($is('s3')),
            TextInput::make('key')->label('Access key')->visible($is('s3')),
            TextInput::make('secret')->label('Secret key')->password()->visible($is('s3')),
            TextInput::make('endpoint')->label('Endpoint (B2 / MinIO / Spaces)')->visible($is('s3')),
            Toggle::make('path_style')->label('Path-style endpoint')->visible($is('s3')),

            TextInput::make('path')->label('Φάκελος / prefix')->placeholder('company-backups')
                ->visible(fn (Get $get) => $get('driver') !== 'local'),
        ];
    }

    private static function formHasRemote(mixed $destinations): bool
    {
        foreach ((array) $destinations as $d) {
            if (is_array($d) && ($d['driver'] ?? 'local') !== 'local') {
                return true;
            }
        }

        return false;
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

    /**
     * Per-row: run a backup via the saved policy, then hand the operator a
     * short-lived signed download link. We DON'T return the file from the action
     * (Livewire buffers a returned download fully in memory + base64) — the link
     * points at a route that streams it from disk (CompanyBackupDownloadController).
     */
    public static function downloadNow(): Action
    {
        return Action::make('backup_download_now')
            ->label('Λήψη αντιγράφου τώρα')
            ->icon('heroicon-o-arrow-down-on-square')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Δημιουργεί αντίγραφο με την αποθηκευμένη πολιτική (καταγράφεται + στέλνεται στους προορισμούς) και δίνει σύνδεσμο λήψης.')
            ->action(function (Company $record): void {
                $settings = $record->backupSetting;
                if ($settings === null) {
                    Notification::make()->title('Ρύθμισε πρώτα τα «Αυτόματα αντίγραφα»')->warning()
                        ->body('Χρειάζεται μια πολιτική (περιεχόμενο / μυστικά / διατήρηση) για το αντίγραφο.')->send();

                    return;
                }

                $run = app(CompanyBackupRunner::class)->run($record, $settings, 'manual');

                if (! $run->isDownloadable()) {
                    Notification::make()->title('Αποτυχία: '.$run->status)
                        ->body($run->message ?? 'Δεν δημιουργήθηκε τοπικό αρχείο για λήψη.')->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Το αντίγραφο είναι έτοιμο')
                    ->body('Κατάσταση: '.$run->status.' · Μέγεθος: '.number_format(((int) $run->bytes) / 1024, 1).' KB'
                        .($run->status === 'partial' ? ' — κάποιοι απομακρυσμένοι προορισμοί απέτυχαν (δες «Αντίγραφα ασφαλείας»).' : ''))
                    ->color($run->statusColor())
                    ->persistent()
                    ->actions([
                        Action::make('download')
                            ->label('Λήψη')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->url(self::downloadUrl($run), shouldOpenInNewTab: true)
                            ->button(),
                    ])
                    ->send();
            });
    }

    /** Short-lived signed URL to stream a finished bundle (see the download route). */
    private static function downloadUrl(CompanyBackupRun $run): string
    {
        return URL::temporarySignedRoute('company-backups.download', now()->addMinutes(15), ['run' => $run->getKey()]);
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
                    ->label('Διαγραφή ακόμη κι αν υπάρχουν υποβεβλημένα στην ΑΑΔΕ')->default(false),
                Toggle::make('execute')
                    ->label('Εκτέλεση (αλλιώς προεπισκόπηση)')->default(false),
            ])
            ->action(function (array $data, Company $record): void {
                $wiper = app(CompanyDataWiper::class);
                $keepParties = (bool) ($data['keep_parties'] ?? false);
                $plan = $wiper->plan($record, $keepParties);
                $evidence = $wiper->legalEvidence($record);

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

                if ($evidence->exists() && ! ($data['force'] ?? false)) {
                    Notification::make()->title('Διακοπή')->danger()
                        ->body('Υποβεβλημένα στην ΑΑΔΕ: '.$evidence->describe()
                            .'. Η τοπική διαγραφή ΔΕΝ τα ακυρώνει εκεί. Κράτησε αντίγραφο και '
                            .'ενεργοποίησε «Διαγραφή ακόμη κι αν…» για να συνεχίσεις.')
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
