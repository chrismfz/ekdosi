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
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * «Φωτογραφίες & βίντεο» of a product (docs/woocommerce-bridge-plan.md §0).
 * Uploads go through ProductMediaService (GD re-encode, thumbnail, EXIF/GPS
 * stripped). On a variable product a photo can be tied to one colour — every
 * size of that colour then shows it. The first photo in the order is the main
 * one (drag to reorder). Visible to operators only — never served publicly.
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Φωτογραφίες & βίντεο';

    /** @var list<int>|null memo for one request */
    private ?array $usedValueIds = null;

    private ?int $primaryImageId = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('attributeValue'))
            ->reorderable('sort')
            ->authorizeReorder(fn () => $this->mayEditMedia())
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->state(fn (ProductMedia $record) => $record->isImage() ? $record->fileUrl('thumb') : null)
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

                IconColumn::make('primary')
                    ->label('Κύρια')
                    ->state(fn (ProductMedia $record) => $record->getKey() === $this->primaryImageId())
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
                    ->url(fn (ProductMedia $record) => $record->fileUrl())
                    ->openUrlInNewTab(),

                Action::make('make_primary')
                    ->label('Κύρια')
                    ->icon('heroicon-o-star')
                    ->visible(fn (ProductMedia $record) => $record->isImage() && $record->getKey() !== $this->primaryImageId())
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
                    ->schema(fn (ProductMedia $record) => [
                        TextInput::make('alt')->label('Περιγραφή (alt — για το e-shop)')->maxLength(255),
                        // Its CURRENT colour stays selectable even if no live variant uses it any more.
                        $this->valueSelect($record->product_attribute_value_id),
                    ])
                    ->action(function (ProductMedia $record, array $data): void {
                        try {
                            $value = $data['product_attribute_value_id'] ?? null;
                            app(ProductMediaService::class)->updateDetails($record, $data['alt'] ?? null, $value !== null ? (int) $value : null);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Δεν αποθηκεύτηκε')->body($e->getMessage())->send();
                        }
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
                    ->acceptedFileTypes(ProductMediaService::imageMimes())
                    ->maxSize((int) config('ekdosi.product_media.max_image_kb'))
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
                    ->helperText('Μέχρι '.round((int) config('ekdosi.product_media.max_video_kb') / 1024).' MB — για μεγαλύτερα, ανέβασέ τα στο YouTube/Vimeo και πρόσθεσε σύνδεσμο.')
                    ->acceptedFileTypes(ProductMediaService::videoMimes())
                    ->maxSize((int) config('ekdosi.product_media.max_video_kb'))
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
            } catch (\Throwable $e) {
                // Disk/GD/runtime failure on ONE file must not lose the others.
                report($e);
                $errors[] = '«'.$file->getClientOriginalName().'»: δεν αποθηκεύτηκε (σφάλμα συστήματος).';
            }
        }

        Notification::make()
            ->{$errors === [] ? 'success' : 'warning'}()
            ->title('Ανέβηκαν: '.$ok)
            ->body($errors === [] ? null : implode("\n", $errors))
            ->send();
    }

    /** Colour/value picker — only on a variable product, limited to values its variants use (+ the record's current one). */
    private function valueSelect(?int $current = null): Select
    {
        return Select::make('product_attribute_value_id')
            ->label('Μόνο για χρώμα / τιμή')
            ->placeholder('Όλες οι παραλλαγές')
            ->options(fn () => $this->valueOptions($current))
            ->visible(fn () => $this->getOwnerRecord()->isVariable());
    }

    /** @return array<int, string> */
    private function valueOptions(?int $current = null): array
    {
        $owner = $this->getOwnerRecord();
        if (! $owner instanceof Product || ! $owner->isVariable()) {
            return [];
        }

        $ids = $this->usedValueIds ??= app(ProductMediaService::class)->usedValueIds($owner);
        if ($current !== null) {
            $ids = array_values(array_unique([...$ids, $current]));
        }

        return Product::orderValues(ProductAttributeValue::query()->with('attribute')->whereKey($ids)->get())
            ->mapWithKeys(fn (ProductAttributeValue $v) => [$v->id => $v->attribute?->name.': '.$v->value])
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    private function pickedValue(array $data): ?int
    {
        $id = $data['product_attribute_value_id'] ?? null;

        return $id !== null && $id !== '' ? (int) $id : null;   // validated by ProductMediaService
    }

    /** First image by sort = the main photo (computed once per render). */
    private function primaryImageId(): ?int
    {
        return $this->primaryImageId ??= ProductMedia::query()
            ->where('product_id', $this->getOwnerRecord()->getKey())
            ->where('kind', ProductMedia::KIND_IMAGE)
            ->orderBy('sort')->orderBy('id')
            ->value('id');
    }

    /** Update permission on the product, and never on a soft-deleted one. */
    private function mayEditMedia(): bool
    {
        $owner = $this->getOwnerRecord();

        return ! ($owner instanceof Product && $owner->trashed())
            && (bool) auth()->user()?->can('update', $owner);
    }
}
