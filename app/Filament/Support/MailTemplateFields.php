<?php

namespace App\Filament\Support;

use App\Services\MailTemplateRenderer;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The two operator-editable invoice-mail template fields («Θέμα» + «Σώμα»),
 * shared by the super_admin CompanyResource form AND the self-service
 * CompanySettings page so their behaviour can't drift.
 *
 * Why this helper exists: the fields used to show the default as a grey
 * `->placeholder()` that VANISHES on the first keystroke — so tweaking one line
 * meant retyping the whole template. Here they are PRE-FILLED with the actual
 * default as real, editable text, and a «Επαναφορά προεπιλογής» hint-action
 * restores it if the operator clears or mangles it.
 *
 * The DB contract is preserved (locked by MailTemplateRendererTest): a template
 * left equal to the app default — or cleared — is dehydrated back to NULL, NOT
 * frozen into the column. So the renderer's "blank → app default at send time"
 * fallback stays live, and a future wording fix to the default still reaches
 * every tenant that never customised it. The placeholder is kept as the hint
 * shown if the field is emptied by hand.
 */
class MailTemplateFields
{
    public static function subject(string $label, string $helperText): TextInput
    {
        return TextInput::make('mail_subject_template')
            ->label($label)
            ->maxLength(191)
            ->placeholder(MailTemplateRenderer::DEFAULT_SUBJECT_TEMPLATE)
            ->helperText($helperText)
            ->afterStateHydrated(self::prefillDefault(MailTemplateRenderer::DEFAULT_SUBJECT_TEMPLATE))
            ->dehydrateStateUsing(self::nullIfDefault(MailTemplateRenderer::DEFAULT_SUBJECT_TEMPLATE))
            ->hintAction(self::resetAction('mail_subject_template', MailTemplateRenderer::DEFAULT_SUBJECT_TEMPLATE));
    }

    public static function body(string $label, string $helperText, int $rows = 10): Textarea
    {
        return Textarea::make('mail_body_template')
            ->label($label)
            ->rows($rows)
            ->placeholder(MailTemplateRenderer::DEFAULT_BODY_TEMPLATE)
            ->helperText($helperText)
            ->afterStateHydrated(self::prefillDefault(MailTemplateRenderer::DEFAULT_BODY_TEMPLATE))
            ->dehydrateStateUsing(self::nullIfDefault(MailTemplateRenderer::DEFAULT_BODY_TEMPLATE))
            ->hintAction(self::resetAction('mail_body_template', MailTemplateRenderer::DEFAULT_BODY_TEMPLATE));
    }

    /**
     * On load, show the default as real editable text when the tenant has no
     * custom value stored — so a small edit doesn't start from a blank box.
     * A stored custom template is left untouched.
     */
    private static function prefillDefault(string $default): Closure
    {
        return function (mixed $state, mixed $component) use ($default): void {
            if (blank($state)) {
                $component->state($default);
            }
        };
    }

    /**
     * On save, collapse "unchanged from default" (and "cleared") back to NULL so
     * the column stays blank — that's what keeps the send-time default fallback
     * alive and lets a future default-wording change reach un-customised tenants.
     * A browser posts textarea newlines as CRLF, so normalise before comparing or
     * an untouched default would look "custom" and get frozen in.
     */
    private static function nullIfDefault(string $default): Closure
    {
        return function (?string $state) use ($default): ?string {
            $normalized = str_replace("\r\n", "\n", trim((string) $state));

            if ($normalized === '' || $normalized === trim($default)) {
                return null;
            }

            return $state;
        };
    }

    private static function resetAction(string $field, string $default): Action
    {
        return Action::make('reset_'.$field)
            ->label('Επαναφορά προεπιλογής')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Επαναφορά προεπιλεγμένου προτύπου;')
            ->modalDescription('Το τρέχον κείμενο θα αντικατασταθεί από το προεπιλεγμένο πρότυπο της εφαρμογής. Ισχύει μετά την «Αποθήκευση».')
            ->modalSubmitActionLabel('Επαναφορά')
            ->action(fn (Set $set) => $set($field, $default));
    }
}
