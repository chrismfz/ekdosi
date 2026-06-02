<?php

namespace App\Filament\RelationManagers;

use App\Models\Attachment;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Συνημμένα — reusable polymorphic attachments tab (customers, invoices, …).
 *
 * Files upload to a private disk; on create we capture the metadata
 * (original name, mime, size, uploader) and stamp `company_id` like the other
 * tenant-owned child managers. Download streams through an authenticated
 * Filament action (the file is never publicly served).
 */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Συνημμένα';

    protected static ?string $recordTitleAttribute = 'original_name';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Αρχείο')
                    ->required()
                    ->disk('local')
                    ->directory('attachments')
                    ->visibility('private')
                    ->storeFileNamesIn('original_name')
                    ->maxSize(20 * 1024)   // 20 MB
                    ->columnSpanFull(),

                TextInput::make('title')
                    ->label('Τίτλος / περιγραφή (προαιρετικό)')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_name')
                    ->label('Αρχείο')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (Attachment $r): ?string => $r->title),

                TextColumn::make('size')
                    ->label('Μέγεθος')
                    ->formatStateUsing(fn ($state, Attachment $r): string => $r->humanSize())
                    ->alignRight(),

                TextColumn::make('uploadedBy.name')
                    ->label('Ανέβηκε από')
                    ->placeholder('Σύστημα'),

                TextColumn::make('created_at')
                    ->label('Ημερομηνία')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Νέο συνημμένο')
                    ->mutateDataUsing(function (array $data): array {
                        $path = $data['path'] ?? null;
                        $disk = 'local';

                        $data['company_id'] = Filament::getTenant()?->getKey();
                        $data['uploaded_by_user_id'] = auth()->id();
                        $data['disk'] = $disk;
                        $data['size'] = ($path && Storage::disk($disk)->exists($path))
                            ? Storage::disk($disk)->size($path)
                            : null;
                        $data['mime_type'] = ($path && Storage::disk($disk)->exists($path))
                            ? Storage::disk($disk)->mimeType($path)
                            : null;
                        // storeFileNamesIn already set original_name; fall back to basename.
                        $data['original_name'] = $data['original_name'] ?? ($path ? basename($path) : 'αρχείο');

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Λήψη')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Attachment $record) {
                        abort_unless(
                            (int) $record->company_id === (int) Filament::getTenant()?->getKey(),
                            403,
                        );
                        abort_unless(Storage::disk($record->disk)->exists($record->path), 404);

                        return Storage::disk($record->disk)->download($record->path, $record->original_name);
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
