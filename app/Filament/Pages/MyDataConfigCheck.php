<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\MyDataCluster;
use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Filament\Resources\VatCategories\VatCategoryResource;
use App\Models\Company;
use App\Services\MyData\ConfigAuditResult;
use App\Services\MyData\ConfigAuditRow;
use App\Services\MyData\MyDataConfigAudit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Κονσόλα myDATA — «Έλεγχος ρυθμίσεων». A structured, click-to-fix view over the
 * SAME MyDataConfigAudit the `mydata:preflight` CLI and the Invoice Types
 * readiness badge use. Read-only + LOCAL (no AADE call): it checks this tenant's
 * invoice-type / VAT config against the AADE code tables and links each issue
 * straight to the record that needs fixing — so half the check lives where the
 * config does (Invoice Types), and this is its overview.
 *
 * Gated like its sibling console tabs — the tenant must be able to READ from
 * myDATA (canReadMyData) + hold View:MyDataConfigCheck — so the cluster keeps one
 * coherent visibility rule. The audit itself is local (no AADE call); it still
 * surfaces the «credentials missing» readiness warning when relevant.
 */
class MyDataConfigCheck extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = MyDataCluster::class;

    protected static ?string $slug = 'config';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.my-data-config-check';

    /** Tenant-readiness row: ['status','name','messages'=>[]]. */
    public ?array $ready = null;

    /** @var list<array{status:string,label:string,detail:?string,messages:list<string>,url:?string}> */
    public array $invoiceTypes = [];

    /** @var list<array{status:string,label:string,detail:?string,messages:list<string>,url:?string}> */
    public array $vatCategories = [];

    public int $errorCount = 0;

    public int $warnCount = 0;

    public bool $clean = false;

    public function mount(): void
    {
        $this->runAudit();
    }

    public static function getNavigationLabel(): string
    {
        return 'Έλεγχος ρυθμίσεων';
    }

    public function getTitle(): string
    {
        return 'Κονσόλα myDATA — Έλεγχος ρυθμίσεων';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->canReadMyData()
            && (bool) auth()->user()?->can('View:MyDataConfigCheck');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label('Επανέλεγχος')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->runAudit()),
        ];
    }

    private function runAudit(): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return;
        }

        $result = app(MyDataConfigAudit::class)->audit($tenant);

        $this->ready = [
            'status' => $result->tenant->status(),
            'name' => $result->tenant->label,
            'messages' => $result->tenant->messages(),
        ];
        $this->invoiceTypes = array_map(
            fn (ConfigAuditRow $r) => $this->row($r, InvoiceTypeResource::class),
            $result->invoiceTypes,
        );
        $this->vatCategories = array_map(
            fn (ConfigAuditRow $r) => $this->row($r, VatCategoryResource::class),
            $result->vatCategories,
        );

        $this->errorCount = $result->errorCount();
        $this->warnCount = $result->warnCount();
        $this->clean = $result->isClean();
    }

    /**
     * @param  class-string  $resource
     * @return array{status:string,label:string,detail:?string,messages:list<string>,url:?string}
     */
    private function row(ConfigAuditRow $r, string $resource): array
    {
        return [
            'status' => $r->status(),
            'label' => $r->label,
            'detail' => $r->detail,
            'messages' => $r->messages(),
            'url' => $this->editUrl($resource, $r->modelId),
        ];
    }

    private function editUrl(string $resource, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $resource::getUrl('edit', [
            'record' => $id,
            'tenant' => Filament::getTenant(),
        ]);
    }
}
