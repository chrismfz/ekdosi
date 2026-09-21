<?php

namespace App\Filament\Support\Tags;

use App\Models\Tag;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reusable Filament controls for the tenant-scoped tag system, shared by the
 * Customer / Supplier / Product / Invoice resources so all four behave the
 * same:
 *   - field()       — the multi-select + inline-create input for forms
 *   - filter()      — a multi-select table filter (scales to many tags)
 *   - column()      — a badge column of the record's tags
 *   - pinnedTabs()  — Έξοδα-style fast-filter tabs for the pinned tags that
 *                     are actually used on a given model
 *   - bulkAttachAction() — attach tags to a selection (e.g. filed invoices
 *                     that can't be edited via the form)
 *
 * Everything is scoped to the ambient Filament tenant.
 */
class TagControls
{
    /** The «Ετικέτες» multi-select with inline create, wired to the model's tags() relationship. */
    public static function field(): Select
    {
        return Select::make('tags')
            ->label('Ετικέτες')
            ->relationship(
                name: 'tags',
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->where('company_id', Filament::getTenant()?->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            )
            ->multiple()
            ->preload()
            ->searchable()
            ->createOptionForm(static::createForm())
            ->createOptionUsing(fn (array $data): int => static::createTag($data))
            ->helperText('Ελεύθερες ετικέτες (π.χ. συχνός, χονδρική, VIP). Φτιάξε νέα με «+».');
    }

    /**
     * The «Ετικέτες» multi-select for a surface with NO model bound to the form
     * (e.g. a page-level create/edit modal that persists the record itself). Same
     * vocabulary + inline-create as field(), but it works on plain option ids: the
     * caller reads `$data['tags']` and syncs the pivot by hand after saving the
     * record. Returns tag ids as its value.
     */
    public static function plainField(): Select
    {
        return Select::make('tags')
            ->label('Ετικέτες')
            ->multiple()
            ->searchable()
            ->options(fn (): array => static::tagOptions())
            ->createOptionForm(static::createForm())
            ->createOptionUsing(fn (array $data): int => static::createTag($data))
            ->helperText('Ελεύθερες ετικέτες (π.χ. RouterOS, VPN, VIP). Φτιάξε νέα με «+».');
    }

    /** Multi-select filter — keeps working with dozens of tags (unlike tabs). */
    public static function filter(): SelectFilter
    {
        return SelectFilter::make('tags')
            ->label('Ετικέτες')
            ->multiple()
            ->options(fn (): array => static::tagOptions())
            ->query(function (Builder $query, array $data): Builder {
                $ids = array_filter($data['values'] ?? []);
                if ($ids === []) {
                    return $query;
                }

                return $query->whereHas('tags', fn (Builder $q) => $q->whereIn('tags.id', $ids));
            });
    }

    /** Badge column of the record's tags. */
    public static function column(): TextColumn
    {
        return TextColumn::make('tags.name')
            ->label('Ετικέτες')
            ->badge()
            ->color('info')
            ->placeholder('—')
            ->toggleable();
    }

    /**
     * Fast-filter tabs (Έξοδα-style) for this model: «Όλα» + one tab per
     * PINNED tag that's actually attached to ≥1 record of the model — so a
     * product-only pinned tag never shows up as a noise tab on the Customers
     * list. Each tab carries a count badge (e.g. «χονδρική (12)»).
     *
     * @param  class-string  $modelClass
     * @return array<string, Tab>
     */
    public static function pinnedTabs(string $modelClass): array
    {
        return ['all' => Tab::make('Όλα')] + static::tagTabs($modelClass);
    }

    /**
     * Just the pinned-tag tabs (no «Όλα») — so a list that already defines its
     * own tabs (e.g. Έξοδα's economic buckets) can append the tag tabs after
     * them. Keyed `tag_{id}`; each carries a count badge.
     *
     * @param  class-string  $modelClass
     * @return array<string, Tab>
     */
    public static function tagTabs(string $modelClass): array
    {
        // The tenant's pinned tags (ordered). We tab only the ones actually
        // used on this model — determined by the single grouped count below.
        $pinnedTags = Tag::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('is_pinned', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($pinnedTags->isEmpty()) {
            return [];
        }

        $model = new $modelClass;
        $table = $model->getTable();
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);

        // ONE grouped query: non-trashed records of this model type carrying
        // one of the tenant's pinned tags, counted per tag. Joining taggables→
        // the model table (and scoping to the tenant pinned-tag ids) keeps it
        // tenant-safe and avoids the per-tag N+1 + the cross-tenant id pull.
        $counts = DB::table('taggables')
            ->join($table, $table.'.id', '=', 'taggables.taggable_id')
            ->where('taggables.taggable_type', $modelClass)
            ->whereIn('taggables.tag_id', $pinnedTags->pluck('id'))
            ->when($usesSoftDeletes, fn ($q) => $q->whereNull($table.'.deleted_at'))
            ->groupBy('taggables.tag_id')
            ->selectRaw('taggables.tag_id as tag_id, COUNT(*) as cnt')
            ->pluck('cnt', 'tag_id');

        $tabs = [];

        foreach ($pinnedTags as $tag) {
            $count = (int) ($counts[$tag->id] ?? 0);
            // Hide a pinned tag that has no (live) records on this model — a
            // product-only tag shouldn't show as an empty tab on Customers.
            if ($count === 0) {
                continue;
            }

            $tabs['tag_'.$tag->id] = Tab::make($tag->name)
                ->badge($count)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('tags', fn (Builder $q) => $q->whereKey($tag->id)));
        }

        return $tabs;
    }

    /**
     * Bulk action to attach tags to the selected rows — the way to tag
     * records you can't edit through the form (e.g. filed invoices).
     */
    public static function bulkAttachAction(): BulkAction
    {
        return BulkAction::make('attach_tags')
            ->label('Ετικέτες')
            ->icon('heroicon-o-tag')
            ->modalHeading('Προσθήκη ετικετών στα επιλεγμένα')
            ->modalSubmitActionLabel('Προσθήκη')
            ->schema([
                Select::make('tags')
                    ->label('Ετικέτες')
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->options(fn (): array => static::tagOptions())
                    ->createOptionForm(static::createForm())
                    ->createOptionUsing(fn (array $data): int => static::createTag($data)),
            ])
            ->action(function (Collection $records, array $data): void {
                $ids = array_filter($data['tags'] ?? []);
                foreach ($records as $record) {
                    $record->tags()->syncWithoutDetaching($ids);
                }
                Notification::make()
                    ->title('Προστέθηκαν ετικέτες σε '.$records->count().' εγγραφές')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /* ===================== internals ===================== */

    /** @return array<int, string> */
    private static function tagOptions(): array
    {
        return Tag::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /** Inline "create a tag" mini-form (shared by field() + bulkAttachAction()). */
    private static function createForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Όνομα')
                ->required()
                ->maxLength(60),
            ColorPicker::make('color')
                ->label('Χρώμα (προαιρετικό)'),
            Toggle::make('is_pinned')
                ->label('Εμφάνιση ως καρτέλα (fast filter)')
                ->default(false),
        ];
    }

    /** Persist an inline-created tag (company_id is not auto-filled by the scope). */
    private static function createTag(array $data): int
    {
        return Tag::create([
            'company_id' => Filament::getTenant()?->getKey(),
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ])->getKey();
    }
}
