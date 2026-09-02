<?php

namespace App\Filament\Resources\ProductCategories\Schemas;

use App\Filament\Pages\MyDataCodeGuide;
use App\Support\MyData\ClassificationGuidance;
use App\Support\MyDataOptions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description_short')
                    ->label('Name')
                    ->required()
                    ->maxLength(120)
                    ->columnSpan(2),

                TextInput::make('markup')
                    ->label('Default markup %')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->maxValue(999.99)
                    ->suffix('%')
                    ->helperText('Default sell-price markup % for new products in this category. Applied as sell_price = buy_price × (1 + markup/100).'),

                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),

                // MYD-5: optional per-category myDATA E3 override. The COMMON case
                // for a mixed goods+services invoice is to set just the category
                // BUCKET here (goods vs services); the E3 TYPE keeps coming from the
                // invoice type (it's channel-driven — wholesale vs retail). Leave
                // blank to inherit the invoice type's default entirely.
                // Restricted to the three goods/services/products buckets on
                // purpose (MYD-5 review F1): those are the item-nature categories
                // that vary per product and stay valid paired with the invoice
                // type's E3 code. Offering the full §8.8 enum here would let an
                // operator pick a bucket AADE forbids for the type → a live [307]/
                // [313] rejection with no local warning.
                Select::make('mydata_income_class_category')
                    ->label('myDATA: Κατηγορία εσόδων (αγαθά/υπηρεσίες)')
                    // One source for the three item-nature buckets (ClassificationGuidance),
                    // shared with the product info block + the list columns.
                    ->options(ClassificationGuidance::bucketOptions())
                    ->native(false)
                    ->helperText(fn (): HtmlString => MyDataCodeGuide::helperText(
                        'Το κύριο πεδίο για μικτά τιμολόγια: π.χ. «Εμπορεύματα» → αγαθά, «Υπηρεσίες» → υπηρεσίες. '
                        .'Κάθε γραμμή προϊόντος αυτής της κατηγορίας δηλώνεται έτσι. Κενό = κληρονομεί τον τύπο παραστατικού.'
                    ))
                    ->columnSpan(2),

                Select::make('mydata_income_class')
                    ->label('myDATA: Χαρακτηρισμός E3 (προχωρημένο)')
                    ->options(MyDataOptions::incomeClassificationTypes())
                    ->searchable()
                    ->preload()
                    ->helperText('Προαιρετικό override του κωδικού E3_561_xxx. Συνήθως αφήνεται κενό ώστε ο τύπος E3 να ακολουθεί το κανάλι του παραστατικού (χονδρική/λιανική).'),
            ])
            ->columns(3);
    }
}
