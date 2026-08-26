<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InteractsWithAssistant;
use App\Models\Company;
use App\Support\Settings\SystemSettings;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use UnitEnum;

/**
 * AI «Βοηθός» — the in-app read-only chat (Phase-1), dedicated-page surface. The
 * AssistantRunner enforces tenant + per-tool permission + the monthly cap; the
 * same engine backs the floating AssistantWidget. Visible only when the assistant
 * is enabled both globally and for the tenant.
 */
class Assistant extends Page
{
    use InteractsWithAssistant;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|UnitEnum|null $navigationGroup = 'Σύστημα';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.assistant';

    public static function getNavigationLabel(): string
    {
        return 'Βοηθός AI';
    }

    public function getTitle(): string
    {
        return 'Βοηθός AI';
    }

    public static function canAccess(): bool
    {
        return static::assistantAvailable();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Shared gate (reused by the page nav AND the floating widget injection). */
    public static function assistantAvailable(): bool
    {
        return app(SystemSettings::class)->bool('system.ai_enabled', (bool) config('ekdosi.ai.enabled'))
            && Filament::getTenant() instanceof Company
            && (bool) Filament::getTenant()?->ai_assistant_enabled
            && auth()->check();
    }

    public function send(): void
    {
        $this->runAssistant();
    }

    public function clearChat(): void
    {
        $this->resetAssistant();
    }
}
