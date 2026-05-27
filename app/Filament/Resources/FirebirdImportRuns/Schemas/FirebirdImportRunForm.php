<?php

namespace App\Filament\Resources\FirebirdImportRuns\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * PR #30 — Upload form for a new Firebird import.
 *
 * Operator workflow:
 *   1. Pick the `.fbk` produced by `gbak` on the legacy box.
 *   2. (Optional) tweak host / user — defaults match what the legacy
 *      app uses; password is the only thing the operator normally
 *      types.
 *   3. Submit → file streams to storage, run row created, queue
 *      job dispatched.
 *
 * After the job is dispatched, the operator lands on the View page
 * for the new run; it auto-refreshes (set on the Infolist) to show
 * status transitions.
 */
class FirebirdImportRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Backup file')
                    ->description('Upload the `.fbk` produced by `gbak` on the legacy Firebird host. The file uploads privately to ekdosi\'s storage; it is deleted automatically after a successful import.')
                    ->schema([
                        FileUpload::make('upload')
                            ->label('Firebird backup (.fbk)')
                            ->required()
                            ->disk('local')
                            ->directory('firebird-imports')
                            ->visibility('private')
                            ->preserveFilenames()
                            ->acceptedFileTypes(['application/octet-stream', 'application/x-firebird-backup'])
                            ->maxSize(500 * 1024)  // 500 MB
                            ->helperText('Max 500 MB. Larger backups: SCP onto the host + use the artisan command.')
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
                            ->required()
                            ->maxLength(255)
                            ->helperText('Held in-memory only — never stored on the run row. The legacy default is masterkey.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
