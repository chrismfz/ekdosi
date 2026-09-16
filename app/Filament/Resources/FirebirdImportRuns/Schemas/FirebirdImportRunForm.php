<?php

namespace App\Filament\Resources\FirebirdImportRuns\Schemas;

use App\Models\FirebirdImportRun;
use App\Services\Etl\FirebirdConnectionTester;
use Filament\Actions\Action as FormAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * PR #30 — Upload form for a new Firebird import.
 *
 * Operator workflow:
 *   1. Pick the `.fbk` produced by `gbak` on the legacy box,
 *      OR the already-restored `.fdb` directly.
 *   2. (Optional) tweak host / user — defaults match what the legacy
 *      app uses; password is the only thing the operator normally
 *      types.
 *   3. Submit → file streams to storage, run row created, queue
 *      job dispatched.
 *
 * For files that exceed php.ini's `upload_max_filesize`, the form
 * surfaces an equivalent artisan command the operator can run on
 * the host directly (bypassing Filament/Livewire upload entirely).
 *
 * After the job is dispatched, the operator lands on the View page
 * for the new run; it auto-refreshes (set on the Infolist) to show
 * status transitions.
 */
class FirebirdImportRunForm
{
    public static function configure(Schema $schema): Schema
    {
        $uploadMax = ini_get('upload_max_filesize') ?: '?';
        $postMax = ini_get('post_max_size') ?: '?';
        $uploadMaxBytes = self::iniBytes($uploadMax);
        $postMaxBytes = self::iniBytes($postMax);
        $serverEffectiveLimit = min(
            $uploadMaxBytes ?: PHP_INT_MAX,
            $postMaxBytes ?: PHP_INT_MAX,
        );
        $serverEffectiveLimitMb = $serverEffectiveLimit !== PHP_INT_MAX
            ? round($serverEffectiveLimit / 1024 / 1024, 0).' MB'
            : 'unlimited';

        $tenant = Filament::getTenant();
        $companyIdForArtisan = $tenant?->getKey() ?? 'N';

        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Firebird')
                            ->icon('heroicon-o-circle-stack')
                            ->schema([
                                Section::make('Backup file')
                                    ->description('Upload either a `.fbk` (gbak backup — the job will restore it first) or a `.fdb` (already-restored Firebird DB — used directly). The file uploads privately to ekdosi\'s storage and is deleted automatically after a successful import.')
                                    ->schema([
                                        FileUpload::make('upload')
                                            ->label('Firebird backup (.fbk) or database (.fdb)')
                                            ->required(fn (Get $get): bool => ! self::hasEpsilon($get) && ! self::hasLive($get))
                                            ->disk('local')
                                            ->directory('firebird-imports')
                                            ->visibility('private')
                                            ->preserveFilenames()
                                            // NO acceptedFileTypes constraint. The blind
                                            // review verified that real .fbk files are
                                            // detected as `image/x-atari-degas` by
                                            // Symfony's FileinfoMimeTypeGuesser (the
                                            // Firebird format apparently shares a magic
                                            // number with that retro raster). Both
                                            // `application/octet-stream` and any
                                            // `application/x-firebird-*` would reject
                                            // every real backup. Validation falls back
                                            // to: (a) the extension hint in the form
                                            // label, (b) gbak's own rejection of
                                            // non-Firebird input with a clear stderr
                                            // that we surface as the failure reason.
                                            ->maxSize(500 * 1024)  // 500 MB
                                            ->helperText(new HtmlString(sprintf(
                                                '<strong>This server\'s PHP limits:</strong> '
                                                .'<code>upload_max_filesize=%s</code>, '
                                                .'<code>post_max_size=%s</code> → '
                                                .'effective max upload <strong>%s</strong>. '
                                                .'Filament-side cap is 500 MB; files larger '
                                                .'than the server limit will fail with a '
                                                .'generic "Error during upload" — fix php.ini '
                                                .'or use the artisan command below.',
                                                e($uploadMax),
                                                e($postMax),
                                                e($serverEffectiveLimitMb),
                                            )))
                                            ->storeFileNamesIn('original_file_name')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Firebird connection (for gbak restore)')
                                    ->description('The host running gbak. Defaults match the legacy production box; only the password normally needs typing.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('fb_host')
                                            ->label('Host')
                                            ->default('127.0.0.1')
                                            ->required()
                                            ->maxLength(100)
                                            ->helperText('Almost always 127.0.0.1 — gbak runs locally where the file is.'),

                                        TextInput::make('fb_user')
                                            ->label('User')
                                            ->default('SYSDBA')
                                            ->required()
                                            ->maxLength(100)
                                            ->helperText('gbak needs SYSDBA-equivalent rights to restore.'),

                                        TextInput::make('fb_password')
                                            ->label('Password')
                                            ->password()
                                            ->revealable()
                                            ->required(fn (Get $get): bool => ! self::hasEpsilon($get) && ! self::hasLive($get))
                                            ->maxLength(255)
                                            ->helperText('Held in-memory only — never stored on the run row. The legacy default is masterkey.')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Διπλά ΑΦΜ πελατών')
                                    ->collapsible()
                                    ->collapsed()
                                    ->description('Η παλιά εφαρμογή δεν είχε πεδίο υποκαταστήματος, οπότε ένα υποκατάστημα ήταν ΔΕΥΤΕΡΟΣ πελάτης με το ίδιο ΑΦΜ. Το ekdosi κρατά έναν πελάτη ανά ΑΦΜ, οπότε η εισαγωγή σταματά και ρωτά ποιος κρατά την ταυτότητα. Συμπλήρωσέ το ΜΟΝΟ αν το «Έλεγχος σύνδεσης» (ή η εισαγωγή) σου το ζητήσει. Η παλιά βάση ΔΕΝ πειράζεται ποτέ — μόνο διαβάζεται.')
                                    ->schema([
                                        TextInput::make('afm_keep')
                                            ->label('CUST_ID που κρατούν το ΑΦΜ')
                                            ->placeholder('π.χ. 41, 87')
                                            // Prefilled from the last import that carried a decision:
                                            // the parallel-run week re-imports daily against the SAME
                                            // legacy duplicates, and a blank field means every one of
                                            // those runs refuses in the queue until it is retyped.
                                            ->default(fn (): ?string => FirebirdImportRun::query()
                                                ->where('company_id', Filament::getTenant()?->getKey())
                                                ->whereNotNull('afm_keep')
                                                ->where('afm_keep', '<>', '')
                                                ->latest('id')
                                                ->value('afm_keep'))
                                            ->maxLength(255)
                                            ->rule('regex:/^[0-9\s,]*$/')
                                            ->helperText('Ένα CUST_ID ανά διπλό ΑΦΜ, χωρισμένα με κόμμα. Προσυμπληρώνεται από την τελευταία εισαγωγή που είχε απόφαση. Ο άλλος πελάτης μπαίνει κανονικά — με ΑΦΜ, παραστατικά και ιστορικό — αλλά χωρίς την ταυτότητα ΑΦΜ, και τον τακτοποιείς μετά μέσα στο ekdosi (συγχώνευση ή υποκατάστημα ανά παραστατικό). Ισχύει και για τη «Ζωντανή σύνδεση».')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Or import via the artisan command')
                                    ->collapsible()
                                    ->collapsed()
                                    ->description('Use this when the file exceeds php.ini limits, or for scripting / cutover-day automation. Copy the line below, fill in your password, run on the ekdosi host.')
                                    ->schema([
                                        View::make('filament.import-artisan-snippet')
                                            ->viewData([
                                                'companyId' => $companyIdForArtisan,
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Epsilon Smart (JSON)')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Section::make('Epsilon Smart — εξαγωγές JSON')
                                    ->description('Ανέβασε τα JSON από το Epsilon Smart (Τιμολόγηση). Κάθε αρχείο προαιρετικό — εισάγεται ό,τι δώσεις. Πελάτες (ΑΦΜ), είδη/υπηρεσίες και πωλήσεις (ιστορικά παραστατικά με ΜΑΡΚ) ταιριάζουν με τα στημένα lookups. Επαναλήψιμο — upsert, δεν διπλασιάζει.')
                                    ->schema([
                                        FileUpload::make('customers_json')
                                            ->label('Πελάτες — DataExport-Customers.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->helperText('Match με ΑΦΜ· επωνυμία/ΔΟΥ/διεύθυνση/τηλέφωνο/email/τρόπος πληρωμής.')
                                            ->columnSpanFull(),
                                        FileUpload::make('items_json')
                                            ->label('Είδη — DataExport-Items.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->helperText('Εμπορεύματα → προϊόντα (ΦΠΑ από κλάση, μονάδα, κατηγορία· τιμή = χονδρική ως καθαρή).')
                                            ->columnSpanFull(),
                                        FileUpload::make('services_json')
                                            ->label('Υπηρεσίες — DataExport-Services.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->helperText('Υπηρεσίες → προϊόντα (κατηγορία «Υπηρεσίες»).')
                                            ->columnSpanFull(),
                                        FileUpload::make('sales_json')
                                            ->label('Πωλήσεις — DataExport-Sales.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->helperText('Ιστορικά παραστατικά (με ΜΑΡΚ) → invoices (active, VALID, εξοφλημένα). Match πελάτη με ΑΦΜ· κρατά το νούμερο Epsilon. Καλό είναι να εισαχθούν πρώτα Πελάτες + Είδη. (Χωρίς AADE QR στο PDF — το Epsilon δεν εξάγει το URL.)')
                                            ->columnSpanFull(),
                                        FileUpload::make('payments_json')
                                            ->label('Πληρωμές/Υπόλοιπα — DataExport-Payments-Balances.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->helperText('Εμβάσματα + Εισπράξεις πελατών → πληρωμές «έναντι» (on-account, match ΑΦΜ) που μειώνουν το υπόλοιπο· idempotent με το DocCode. Στο τέλος βγαίνει αναφορά συμφωνίας ekdosi vs Epsilon ανά πελάτη. Τρέξε το ΑΦΟΥ έχουν μπει Πελάτες + Πωλήσεις — και ΜΗΝ ξανα-τρέξεις τις Πωλήσεις μετά (θα διπλο-εξοφλούσε).')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Ζωντανή σύνδεση')
                            ->icon('heroicon-o-server-stack')
                            ->schema([
                                Section::make('Απευθείας σύνδεση σε ζωντανή Firebird')
                                    ->description('Αντί για αρχείο, σύνδεση κατευθείαν στη ΖΩΝΤΑΝΗ βάση του legacy μηχανήματος (IP + διαπιστευτήρια) — χωρίς gbak/upload. Μόνο ανάγνωση (SELECT). Απαιτεί pdo_firebird στον διακομιστή + προσπέλαση στην πόρτα Firebird (3050) του απομακρυσμένου. Πάτησε πρώτα «Έλεγχος σύνδεσης».')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('fb_live_host')
                                            ->label('Host / IP')
                                            ->placeholder('10.23.22.5')
                                            ->maxLength(100)
                                            ->required(fn (Get $get): bool => self::hasLive($get)),
                                        TextInput::make('fb_live_port')
                                            ->label('Πόρτα')
                                            ->numeric()
                                            ->default(3050)
                                            ->minValue(1)->maxValue(65535)
                                            ->helperText('Προεπιλογή Firebird: 3050.'),
                                        TextInput::make('fb_live_database')
                                            ->label('Διαδρομή βάσης (.fdb στον remote)')
                                            ->placeholder('/opt/Data/ekdosi-myip.fdb')
                                            ->maxLength(500)
                                            // A ';' / newline would inject extra params into the Firebird
                                            // DSN (firebird:dbname=host:PATH;charset=…) — block it.
                                            ->rule('not_regex:/[;\r\n]/')
                                            ->helperText('Η απόλυτη διαδρομή του .fdb ΣΤΟΝ απομακρυσμένο διακομιστή. Συμπλήρωσέ τη για να ενεργοποιηθεί η ζωντανή σύνδεση.')
                                            ->columnSpanFull(),
                                        TextInput::make('fb_live_user')
                                            ->label('Χρήστης')
                                            ->default('EKDOSI')
                                            ->maxLength(100)
                                            ->required(fn (Get $get): bool => self::hasLive($get)),
                                        TextInput::make('fb_live_password')
                                            ->label('Κωδικός')
                                            ->password()->revealable()
                                            ->maxLength(255)
                                            ->required(fn (Get $get): bool => self::hasLive($get))
                                            ->helperText('Μόνο στη μνήμη — δεν αποθηκεύεται στη γραμμή εισαγωγής.'),

                                        FormAction::make('test_firebird_connection')
                                            ->label('Έλεγχος σύνδεσης')
                                            ->icon('heroicon-o-signal')
                                            ->color('gray')
                                            ->action(function (Get $get): void {
                                                $db = trim((string) $get('fb_live_database'));
                                                $host = trim((string) $get('fb_live_host'));
                                                if ($db === '' || $host === '') {
                                                    Notification::make()->title('Συμπλήρωσε Host + διαδρομή βάσης πρώτα')->warning()->send();

                                                    return;
                                                }

                                                $result = app(FirebirdConnectionTester::class)->test(
                                                    $host,
                                                    (int) ($get('fb_live_port') ?: 3050),
                                                    $db,
                                                    trim((string) $get('fb_live_user')) ?: 'EKDOSI',
                                                    (string) $get('fb_live_password'),
                                                    Filament::getTenant()?->getKey(),
                                                    // Judge the source under the decision the
                                                    // operator has ALREADY typed, so a re-test
                                                    // after filling the field agrees with the import.
                                                    FirebirdImportRun::parseAfmKeep($get('afm_keep')),
                                                );

                                                if ($result->ok) {
                                                    $body = $result->message
                                                        .($result->missing !== [] ? ' — (λείπουν: '.implode(', ', $result->missing).')' : '');

                                                    // The ΑΦΜ preflight: say it HERE, while the operator is still
                                                    // configuring — not after a gbak restore and a refused import.
                                                    if ($result->afmBlocks()) {
                                                        Notification::make()
                                                            ->title('⚠ Σύνδεση OK — αλλά η εισαγωγή θα σταματήσει')
                                                            ->body(new HtmlString(
                                                                e($body).'<br><strong>'.e((string) $result->afmSummary()).'</strong><br>'
                                                                .nl2br(e(self::firstLines($result->afm->describe(), 8)))
                                                                .'<br>'.e($result->afm->howTo())
                                                                .'<br><em>'.e('Από εδώ: συμπλήρωσε/διόρθωσε τα CUST_ID στο «Διπλά ΑΦΜ πελατών» (καρτέλα Firebird) και ξανακάνε «Έλεγχος σύνδεσης».').'</em>'
                                                            ))
                                                            ->warning()->persistent()->send();

                                                        return;
                                                    }

                                                    Notification::make()
                                                        ->title('✅ Σύνδεση OK')
                                                        ->body($body.(($afm = $result->afmSummary()) !== null ? ' — '.$afm : ''))
                                                        ->success()->send();

                                                    return;
                                                }

                                                Notification::make()
                                                    ->title(match ($result->reason) {
                                                        'driver_missing' => 'Λείπει το pdo_firebird',
                                                        'auth' => 'Απορρίφθηκαν τα διαπιστευτήρια',
                                                        'unreachable' => 'Δεν απαντά ο διακομιστής',
                                                        'no_tables' => 'Συνδέθηκε, λάθος/κενή βάση',
                                                        default => 'Αποτυχία σύνδεσης',
                                                    })
                                                    ->body($result->message)
                                                    ->danger()->persistent()->send();
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /** Cap a multi-line report so one pathological database can't flood the toast. */
    private static function firstLines(string $text, int $max): string
    {
        $lines = explode("\n", $text);
        if (count($lines) <= $max) {
            return $text;
        }

        return implode("\n", array_slice($lines, 0, $max))."\n… (+".(count($lines) - $max).' ακόμη — δες «migrate:firebird --dry-run»)';
    }

    /** True when the operator is configuring a live connection (a DB path typed). */
    public static function hasLive(Get $get): bool
    {
        return filled($get('fb_live_database'));
    }

    /** True when any Epsilon JSON file is staged (→ gates the Firebird fields off). */
    public static function hasEpsilon(Get $get): bool
    {
        return filled($get('customers_json')) || filled($get('items_json'))
            || filled($get('services_json')) || filled($get('sales_json'))
            || filled($get('payments_json'));
    }

    /**
     * Convert a php.ini size string like "8M" / "1G" to bytes for
     * comparison. Mirrors what PHP itself does for these directives
     * (the `min()` of `upload_max_filesize` and `post_max_size` is
     * the EFFECTIVE upload ceiling — `post_max_size` of 8M with
     * `upload_max_filesize` of 100M still caps uploads at 8M).
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
