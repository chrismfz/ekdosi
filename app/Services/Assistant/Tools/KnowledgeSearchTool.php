<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Services\Assistant\KnowledgeBase;

/**
 * Phase 2c (ζ): «βοήθεια & συμβουλή» grounded on a curated KB (`docs/assistant-kb/`).
 * Returns the most relevant excerpts for the operator's question so the Βοηθός can
 * answer app how-to («πώς κόβω πιστωτικό;») and CONFIRMED tax notes — and ONLY those.
 *
 * Grounding discipline (the whole point): the model must answer STRICTLY from the
 * returned excerpts; anything not covered is «δεν το ξέρω με βεβαιότητα — ρώτα
 * λογιστή», never an invented rule, and tax topics always carry a disclaimer. The
 * KB is authored/global content (no tenant data), so no permission gate.
 */
class KnowledgeSearchTool implements AssistantTool
{
    private const GROUNDING =
        'Απάντησε ΑΥΣΤΗΡΑ και ΜΟΝΟ από τα παρακάτω αποσπάσματα. Ό,τι δεν καλύπτεται σε αυτά, '
        .'πες «δεν το ξέρω με βεβαιότητα — επιβεβαίωσέ το με τον λογιστή» και ΜΗΝ το μαντέψεις. '
        .'ΠΟΤΕ μην εφεύρεις φορολογικό κανόνα/συντελεστή. Για φορολογικά θέματα πρόσθεσε πάντα '
        .'σύντομο disclaimer ότι δεν είναι φορολογική συμβουλή. Ανέφερε την πηγή (source) όπου βοηθά.';

    public function name(): string
    {
        return 'knowledge_search';
    }

    public function description(): string
    {
        return 'Ψάξε στην εγκεκριμένη βάση γνώσης της εφαρμογής για «πώς-κάνω» (π.χ. «πώς κόβω '
            .'πιστωτικό;», «πού βλέπω τι μου χρωστάνε;») και επιβεβαιωμένες φορολογικές σημειώσεις. '
            .'ΧΡΗΣΙΜΟΠΟΙΗΣΕ το ΠΡΙΝ απαντήσεις τέτοια ερώτηση — απάντησε ΜΟΝΟ από όσα επιστρέφει· ό,τι '
            .'δεν καλύπτεται, πες «ρώτα λογιστή» και μην το μαντέψεις. Δώσε `query` (η ερώτηση).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Η ερώτηση/όροι αναζήτησης.'],
                'limit' => ['type' => 'integer', 'description' => 'Πόσα αποσπάσματα (1-8, προεπιλογή 4).'],
            ],
            'required' => ['query'],
        ];
    }

    public function permission(): ?string
    {
        // App how-to + accountant-authored notes are safe for any operator; the
        // confirm-nothing (read-only) tool needs no extra gate.
        return null;
    }

    public function run(Company $tenant, array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'Δώσε τι να ψάξω (query).'];
        }

        $limit = max(1, min(8, (int) ($input['limit'] ?? 4)));
        $results = app(KnowledgeBase::class)->search($query, $limit);

        if ($results === []) {
            return [
                'query' => $query,
                'found' => false,
                'results' => [],
                'grounding' => 'Δεν βρέθηκε σχετική εγκεκριμένη γνώση. Πες στον χειριστή ότι δεν το ξέρεις '
                    .'με βεβαιότητα και να το επιβεβαιώσει με τον λογιστή — ΜΗΝ μαντέψεις (ειδικά φορολογικά).',
            ];
        }

        return [
            'query' => $query,
            'found' => true,
            'results' => array_map(static fn (array $r): array => [
                'source' => $r['source'],
                'heading' => $r['heading'],
                'excerpt' => $r['excerpt'],
            ], $results),
            'grounding' => self::GROUNDING,
        ];
    }
}
