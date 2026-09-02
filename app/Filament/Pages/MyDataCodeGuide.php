<?php

namespace App\Filament\Pages;

use App\Support\MyData\CodeReference;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * «Οδηγός κωδικών myDATA» — a read-only glossary of the AADE §8 codes an operator
 * meets in the panel (invoice types, income classification, VAT categories,
 * exemption reasons, business-activity policy), each with «τι είναι / πού
 * χρησιμοποιείται». So a non-accountant can look up «τι είναι το 2.1;» instead of
 * memorising the spec. The forms that pick these codes link here.
 *
 * Deliberately open to ANY authenticated panel user (help, not a privileged
 * action) and NOT wired into Shield's managed page permissions — it carries no
 * tenant data (only the static §8 tables), so there is nothing to gate. Overriding
 * canAccess() keeps it out of the shield:generate / re-provision dance.
 */
class MyDataCodeGuide extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 98;

    protected string $view = 'filament.pages.my-data-code-guide';

    public static function getNavigationLabel(): string
    {
        return 'Οδηγός κωδικών myDATA';
    }

    public function getTitle(): string
    {
        return 'Οδηγός κωδικών myDATA';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Setup';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * The glossary sections for the view.
     *
     * @return list<array{key:string,title:string,intro:string,rows:list<array{code:string,title:string,detail:string}>}>
     */
    public function sections(): array
    {
        return CodeReference::sections();
    }

    /**
     * A small «open the codes guide» link for a form field's helperText. Call
     * inside a helperText closure so getUrl() resolves at render time (with the
     * panel + tenant in scope). Returns markup an operator can click to look up
     * «τι είναι αυτός ο κωδικός;» without knowing it by heart.
     */
    public static function hintLink(string $text = 'Οδηγός κωδικών myDATA'): HtmlString
    {
        return new HtmlString(
            '<a href="'.e(static::getUrl()).'" target="_blank" rel="noopener noreferrer" '
            .'class="text-primary-600 dark:text-primary-400 hover:underline">📖 '.e($text).'</a>'
        );
    }

    /**
     * A field helperText = plain text + the guide link, escaping the text and
     * appending the (already-safe) link markup. One place for the wrap-and-append
     * idiom the code-picking forms share. Call inside a closure so getUrl() resolves
     * at render time.
     */
    public static function helperText(string $text): HtmlString
    {
        return new HtmlString(e($text).' · '.static::hintLink()->toHtml());
    }
}
