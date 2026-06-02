<?php

namespace App\Filament\Resources\FirebirdImportRuns\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
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
                            ->required(fn (Get $get): bool => ! self::hasEpsilon($get))
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
                            ->required(fn (Get $get): bool => ! self::hasEpsilon($get))
                            ->maxLength(255)
                            ->helperText('Held in-memory only — never stored on the run row. The legacy default is masterkey.')
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
                                    ->description('Ανέβασε τα JSON από το Epsilon Smart (Τιμολόγηση). Κάθε αρχείο προαιρετικό — εισάγεται ό,τι δώσεις. Πελάτες (ΑΦΜ) + είδη/υπηρεσίες ταιριάζουν με τα στημένα lookups (ΦΠΑ / μονάδες / κατηγορίες). Επαναλήψιμο — upsert, δεν διπλασιάζει. (Οι πωλήσεις/παραστατικά έρχονται σε επόμενη φάση.)')
                                    ->schema([
                                        FileUpload::make('customers_json')
                                            ->label('Πελάτες — DataExport-Customers.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->preserveFilenames()
                                            ->helperText('Match με ΑΦΜ· επωνυμία/ΔΟΥ/διεύθυνση/τηλέφωνο/email/τρόπος πληρωμής.')
                                            ->columnSpanFull(),
                                        FileUpload::make('items_json')
                                            ->label('Είδη — DataExport-Items.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->preserveFilenames()
                                            ->helperText('Εμπορεύματα → προϊόντα (ΦΠΑ από κλάση, μονάδα, κατηγορία· τιμή = χονδρική ως καθαρή).')
                                            ->columnSpanFull(),
                                        FileUpload::make('services_json')
                                            ->label('Υπηρεσίες — DataExport-Services.json')
                                            ->disk('local')->directory('epsilon-imports')->visibility('private')
                                            ->preserveFilenames()
                                            ->helperText('Υπηρεσίες → προϊόντα (κατηγορία «Υπηρεσίες»).')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /** True when any Epsilon JSON file is staged (→ gates the Firebird fields off). */
    public static function hasEpsilon(Get $get): bool
    {
        return filled($get('customers_json')) || filled($get('items_json')) || filled($get('services_json'));
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
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
