<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * An internal, operator-only note on a tenant-owned record (customer, invoice…).
 * NEVER printed on a PDF, NEVER sent to AADE — purely for the back office. The
 * "internal note per παραστατικό" lives here, distinct from the printed
 * `invoices.notes` field.
 *
 * Grown (not cloned) into the «Σημειώσεις πελάτη» surface: a `title` for a
 * scannable list, a `kind` splitting the short chronological note from a living
 * technical dossier, tenant-scoped `tags` (via HasTags) for cross-cutting
 * classification, and markdown/code-fence rendering so a RouterOS export or an
 * IP table reads correctly.
 */
class Note extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasTags;
    use SoftDeletes;

    /** Origin marker for an imported (ETL-synced) note; NULL = operator-authored. */
    public const SOURCE_BACKUP = 'backup';

    /** Short, append-style note (a call log, a reminder, a merge diff). */
    public const KIND_GENERAL = 'general';

    /** A living, edited-in-place technical dossier (configs, IPs, remote-access ids). */
    public const KIND_TECHNICAL = 'technical';

    protected $fillable = [
        'company_id',
        'notable_type',
        'notable_id',
        'title',
        'kind',
        'body',
        'is_pinned',
        'source',
        'author_user_id',
    ];

    protected $attributes = [
        'kind' => self::KIND_GENERAL,
    ];

    /**
     * Operator-facing kind labels (Greek panel). The single source for the form
     * Select, the filter, and the card badge.
     *
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::KIND_GENERAL => 'Γενική',
            self::KIND_TECHNICAL => 'Τεχνικό δελτίο',
        ];
    }

    /** Operator-facing label for this note's kind (falls back to the raw value). */
    public function kindLabel(): string
    {
        return self::kindOptions()[$this->kind] ?? (string) $this->kind;
    }

    /** Whether the note is import-managed (read-only for operators). */
    public function isImported(): bool
    {
        return $this->source !== null;
    }

    /** Operator-facing label for the note's origin (null = no badge). */
    public function sourceLabel(): ?string
    {
        return $this->source === self::SOURCE_BACKUP ? 'από backup' : null;
    }

    /**
     * The best short label for this note in a list: its title, or the first
     * MEANINGFUL body line when title-less (older/imported notes). «Meaningful»
     * skips blank lines and code-fence markers and strips leading markdown
     * markers — so a technical note that starts with a ``` config block shows
     * the first real config line, not a bare «```».
     */
    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        $firstLine = $this->bodyFirstMeaningfulLine();

        return $firstLine !== '' ? Str::limit($firstLine, 80) : '(χωρίς τίτλο)';
    }

    /**
     * A single-line PLAIN-TEXT snippet of the body for previews/lists: drops
     * code-fence markers + leading markdown markers and collapses whitespace, so
     * a markdown-authored note reads cleanly in a preview (no «#»/«```» noise).
     * NOT HTML — callers render it through {{ }} (escaped); never strip_tags,
     * which would eat a «set x <value>» config fragment.
     */
    public function plainExcerpt(int $limit = 100): string
    {
        return Str::limit($this->bodyPlainText(), $limit);
    }

    /** First non-blank, non-fence body line, leading markdown markers stripped. */
    private function bodyFirstMeaningfulLine(): string
    {
        foreach (preg_split('/\R/', (string) $this->body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '```')) {
                continue;
            }

            return ltrim($trimmed, "#>*-+ \t");
        }

        return '';
    }

    /** The whole body as collapsed plain text (fences + leading markers removed). */
    private function bodyPlainText(): string
    {
        $kept = [];
        foreach (preg_split('/\R/', (string) $this->body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '```')) {
                continue;
            }
            $kept[] = ltrim($trimmed, "#>*-+ \t");
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $kept)) ?? '');
    }

    /**
     * The note body rendered from markdown to SAFE HTML for reading:
     * fenced code blocks / indented code become monospace `<pre>` blocks (so a
     * RouterOS export or an IP table keeps its shape), lists/headings/tables/
     * autolinks work — but raw HTML is ESCAPED and unsafe link schemes
     * (javascript:, data:) are stripped (DOC-8 boundary: an operator-typed note
     * can never smuggle live markup or a hostile link into the panel).
     */
    public function renderedBody(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->body, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]));
    }

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
