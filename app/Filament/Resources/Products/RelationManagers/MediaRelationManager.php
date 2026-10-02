<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductMedia;
use App\Services\Products\ProductMediaService;
use App\Support\Bytes;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * «Φωτογραφίες & βίντεο» of a product (docs/woocommerce-bridge-plan.md §0).
 * Uploads go through ProductMediaService (GD re-encode, thumbnail, EXIF/GPS
 * stripped). On a variable product a photo can be tied to one colour — every
 * size of that colour then shows it.
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Φωτογραφίες & βίντεο';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('attributeValue'))
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->state(fn (ProductMedia $record) => $record->isImage() ? $record->publicUrl('thumb') : null)
                    ->imageSize(64)
                    ->square(),

                TextColumn::make('kind')
                    ->label('Τύπος')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProductMedia::KINDS[$state] ?? $state)
                    ->description(fn (ProductMedia $record) => $record->kind === ProductMedia::KIND_VIDEO_LINK
                        ? $record->url
                        : $record->original_name),

                TextColumn::make('attributeValue.value')
                    ->label('Χρώμα / τιμή')
                    ->placeholder('Όλες οι παραλλαγές')
                    ->visible(fn () => $this->getOwnerRecord()->isVariable()),

                TextColumn::make('alt')
                    ->label('Περιγραφή (alt)')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('size')
                    ->label('Μέγεθος')
                    ->formatStateUsing(fn (?int $state) => Bytes::forHumans($state))
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_primary')
                    ->label('Κύρια')
                    ->boolean(),
            ])
            ->headerActions([
                $this->uploadImagesAction(),
                $this->uploadVideoAction(),
                $this->videoLinkAction(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ProductMedia $record) => $record->publicUrl())
                    ->openUrlInNewTab(),

                Action::make('make_primary')
                    ->label('Κύρια')
                    ->icon('heroicon-o-star')
                    ->visible(fn (ProductMedia $record) => $record->isImage() && ! $record->is_primary)
                    ->authorize(fn () => $this->mayEditMedia())
                    ->action(fn (ProductMedia $record) => app(ProductMediaService::class)->makePrimary($record)),

                Action::make('edit_details')
                    ->label('Στοιχεία')
                    ->icon('heroicon-o-pencil-square')
                    ->authorize(fn () => $this->mayEditMedia())
                    ->fillForm(fn (ProductMedia $record) => [
                        'alt' => $record->alt,
                        'product_attribute_value_id' => $record->product_attribute_value_id,
                    ])
                    ->schema(fn () => [
                        TextInput::make('alt')->label('Περιγραφή (alt — για το e-shop)')->maxLength(255),
                        $this->valueSelect(),
                    ])
                    ->action(function (ProductMedia $record, array $data): void {
                        $valueId = $data['product_attribute_value_id'] ?? null;
                        $record->update([
                            'alt' => $data['alt'] ?? null,
                            'product_attribute_value_id' => $valueId !== null && array_key_exists((int) $valueId, $this->valueOptions())
                                ? (int) $valueId : null,
                        ]);
                    }),

                Action::make('remove')
                    ->label('Διαγραφή')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn () => $this->mayEditMedia())
                    ->action(fn (ProductMedia $record) => app(ProductMediaService::class)->delete($record)),
            ]);
    }

    private function uploadImagesAction(): Action
    {
        return Action::make('upload_images')
            ->label('Ανέβασμα φωτογραφιών')
            ->icon('heroicon-o-photo')
            ->authorize(fn () => $this->mayEditMedia())
            ->schema(fn () => [
                FileUpload::make('files')
                    ->label('Φωτογραφίες')
                    ->multiple()
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->maxSize((int) config('ekdosi.product_media.max_image_kb', 10240))
                    ->storeFiles(false)
                    ->required(),
                $this->valueSelect(),
                TextInput::make('alt')->label('Περιγραφή (alt — προαιρετικό, για όλες)')->maxLength(255),
            ])
            ->action(function (array $data): void {
                $this->storeEach((array) ($data['files'] ?? []), fn (TemporaryUploadedFile $file) => app(ProductMediaService::class)
                    ->storeImage($this->getOwnerRecord(), $file->getRealPath(), $file->getClientOriginalName(), $this->pickedValue($data), $data['alt'] ?? null));
            });
    }

    private function uploadVideoAction(): Action
    {
        return Action::make('upload_video')
            ->label('Ανέβασμα βίντεο')
            ->icon('heroicon-o-film')
            ->color('gray')
            ->authorize(fn () => $this->mayEditMedia())
            ->schema(fn () => [
                FileUpload::make('file')
                    ->label('Βίντεο (MP4 / WebM / MOV)')
                    ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime'])
                    ->maxSize((int) config('ekdosi.product_media.max_video_kb', 51200))
                    ->storeFiles(false)
                    ->required(),
                $this->valueSelect(),
            ])
            ->action(function (array $data): void {
                $this->storeEach([$data['file'] ?? null], fn (TemporaryUploadedFile $file) => app(ProductMediaService::class)
                    ->storeVideo($this->getOwnerRecord(), $file->getRealPath(), $file->getClientOriginalName(), $this->pickedValue($data)));
            });
    }

    private function videoLinkAction(): Action
    {
        return Action::make('video_link')
            ->label('Σύνδεσμος βίντεο')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->authorize(fn () => $this->mayEditMedia())
            ->schema(fn () => [
                TextInput::make('url')
                    ->label('Σύνδεσμος (YouTube, Vimeo, Instagram, TikTok, Facebook)')
                    ->url()
                    ->required()
                    ->maxLength(500),
                $this->valueSelect(),
            ])
            ->action(function (array $data): void {
                try {
                    app(ProductMediaService::class)->addVideoLink($this->getOwnerRecord(), (string) $data['url'], $this->pickedValue($data));
                    Notification::make()->success()->title('Προστέθηκε ο σύνδεσμος')->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Δεν προστέθηκε')->body($e->getMessage())->send();
                }
            });
    }

    /**
     * Store each upload independently: one bad file doesn't lose the others.
     *
     * @param  array<int, mixed>  $files
     */
    private function storeEach(array $files, callable $store): void
    {
        $ok = 0;
        $errors = [];
        foreach (array_filter($files) as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }
            try {
                $store($file);
                $ok++;
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        Notification::make()
            ->{$errors === [] ? 'success' : 'warning'}()
            ->title('Ανέβηκαν: '.$ok)
            ->body($errors === [] ? null : implode("\n", $errors))
            ->send();
    }

    /** Colour/value picker — only on a variable product, limited to values its variants use. */
    private function valueSelect(): Select
    {
        return Select::make('product_attribute_value_id')
            ->label('Μόνο για χρώμα / τιμή')
            ->placeholder('Όλες οι παραλλαγές')
            ->options(fn () => $this->valueOptions())
            ->visible(fn () => $this->getOwnerRecord()->isVariable());
    }

    /** @return array<int, string> */
    private function valueOptions(): array
    {
        $owner = $this->getOwnerRecord();
        if (! $owner instanceof Product || ! $owner->isVariable()) {
            return [];
        }

        $ids = DB::table('product_variant_values')
            ->join('products', 'products.id', '=', 'product_variant_values.product_id')
            ->where('products.parent_product_id', $owner->getKey())
            ->whereNull('products.deleted_at')
            ->distinct()
            ->pluck('product_variant_values.product_attribute_value_id');

        return Product::orderValues(ProductAttributeValue::query()->with('attribute')->whereKey($ids)->get())
            ->mapWithKeys(fn (ProductAttributeValue $v) => [$v->id => $v->attribute?->name.': '.$v->value])
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    private function pickedValue(array $data): ?int
    {
        $id = $data['product_attribute_value_id'] ?? null;

        return $id !== null && array_key_exists((int) $id, $this->valueOptions()) ? (int) $id : null;
    }

    private function mayEditMedia(): bool
    {
        return (bool) auth()->user()?->can('update', $this->getOwnerRecord());
    }
}
