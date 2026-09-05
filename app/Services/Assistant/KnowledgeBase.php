<?php

namespace App\Services\Assistant;

/**
 * Phase 2c (ζ): the curated knowledge base behind the `knowledge_search` tool.
 *
 * RAG-lite, NO external call: loads the markdown under `docs/assistant-kb/`, splits
 * each file into sections by its `#`/`##`/`###` headings, and ranks the sections by
 * how many of the query's terms appear in them (heading matches weighted higher).
 * The Βοηθός answers STRICTLY from the returned excerpts — anything not covered is
 * «δεν το ξέρω, ρώτα λογιστή», never an invented (tax) rule.
 *
 * The KB is authored content (app how-to + accountant-confirmed tax notes), NOT
 * tenant data, so this is global and read-only. `README.md` is meta and excluded.
 */
class KnowledgeBase
{
    private const EXCERPT_CHARS = 600;

    /** Folded Greek function words that would match nearly every section — dropped
     *  from query terms so ranking isn't polluted (min term length is 2, so short
     *  domain terms like «ΔΑ»/«Ε3» stay searchable). */
    private const STOPWORDS = [
        'και', 'για', 'του', 'της', 'των', 'τον', 'την', 'τα', 'το', 'οι', 'στο', 'στη',
        'στον', 'στην', 'απο', 'ειναι', 'θα', 'δεν', 'μου', 'μας', 'σας', 'πωσ', 'που',
        'ποια', 'ποιο', 'με', 'σε', 'να', 'ως', 'κ', 'ή',
    ];

    /** @var list<array{source: string, heading: string, body: string}>|null */
    private ?array $sectionsCache = null;

    /** Greek accent fold so «ΦΠΑ»/«φπα» and «νησιά»/«νησια» match. */
    private const ACCENT_MAP = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
    ];

    public function __construct(private readonly ?string $path = null) {}

    private function dir(): string
    {
        return $this->path ?? base_path('docs/assistant-kb');
    }

    /**
     * The most relevant KB sections for a free-text query, best first.
     *
     * @return list<array{source: string, heading: string, excerpt: string, score: int}>
     */
    public function search(string $query, int $limit = 4): array
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($this->sections() as $section) {
            if (trim($section['body']) === '') {
                continue; // heading with no body → nothing to ground on
            }
            $headHay = $this->fold($section['heading']);
            $bodyHay = $this->fold($section['heading'].' '.$section['body']);
            $score = 0;
            foreach ($terms as $t) {
                if (! str_contains($bodyHay, $t)) {
                    continue;
                }
                // Heading hits count double — a section titled like the question wins.
                $score += str_contains($headHay, $t) ? 2 : 1;
            }
            if ($score > 0) {
                $section['score'] = $score;
                $scored[] = $section;
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $s): array => [
            'source' => $s['source'],
            'heading' => $s['heading'],
            'excerpt' => mb_substr(trim($s['body']), 0, self::EXCERPT_CHARS),
            'score' => $s['score'],
        ], array_slice($scored, 0, max(1, $limit)));
    }

    /**
     * Every heading-delimited section across the KB files (README excluded).
     *
     * @return list<array{source: string, heading: string, body: string}>
     */
    private function sections(): array
    {
        if ($this->sectionsCache !== null) {
            return $this->sectionsCache; // static within a request — parse once
        }

        $dir = $this->dir();
        if (! is_dir($dir)) {
            return $this->sectionsCache = [];
        }

        $out = [];
        foreach (glob(rtrim($dir, '/').'/*.md') ?: [] as $file) {
            $base = basename($file);
            if (strcasecmp($base, 'README.md') === 0) {
                continue;
            }
            $heading = '';
            $body = '';
            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
                if (preg_match('/^#{1,6}\s+(.*)$/', $line, $m) === 1) {
                    if ($heading !== '' || trim($body) !== '') {
                        $out[] = ['source' => $base, 'heading' => $heading, 'body' => $body];
                    }
                    $heading = trim($m[1]);
                    $body = '';

                    continue;
                }
                $body .= $line."\n";
            }
            if ($heading !== '' || trim($body) !== '') {
                $out[] = ['source' => $base, 'heading' => $heading, 'body' => $body];
            }
        }

        return $this->sectionsCache = $out;
    }

    /**
     * Query → distinct search terms (folded, length ≥ 3 to drop noise words).
     *
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $this->fold($query)) ?: [];
        $terms = [];
        foreach ($parts as $p) {
            // Length ≥ 2 keeps short domain terms (ΔΑ, Ε3), but drop Greek function
            // words so «και/για/πού» don't match every section and skew ranking.
            if (mb_strlen($p) >= 2 && ! in_array($p, self::STOPWORDS, true)) {
                $terms[$p] = true;
            }
        }

        return array_keys($terms);
    }

    private function fold(string $s): string
    {
        return strtr(mb_strtolower($s), self::ACCENT_MAP);
    }
}
