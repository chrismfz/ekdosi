<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Services\Assistant\KnowledgeBase;
use App\Services\Assistant\Tools\KnowledgeSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2c (ζ): the curated-KB search behind `knowledge_search`. The load-bearing
 * behaviour is grounding — return only real excerpts, and a clear «not covered»
 * signal so the Βοηθός says «ρώτα λογιστή» instead of inventing a rule.
 */
class KnowledgeSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/kb-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/README.md', "# Meta\nΟδηγίες, όχι περιεχόμενο — να ΜΗΝ επιστρέφεται.\n");
        file_put_contents($this->dir.'/howto.md',
            "# Πώς κόβω πιστωτικό;\nΆνοιξε το παραστατικό και πάτησε «Έκδοση πιστωτικού».\n\n".
            "# Πού βλέπω οφειλές;\nΣτην Ηλικίωση οφειλών και στην Καρτέλα του πελάτη.\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_search_returns_the_relevant_section(): void
    {
        $res = (new KnowledgeBase($this->dir))->search('πώς κόβω πιστωτικό');

        $this->assertNotEmpty($res);
        $this->assertSame('Πώς κόβω πιστωτικό;', $res[0]['heading']);
        $this->assertStringContainsString('Έκδοση πιστωτικού', $res[0]['excerpt']);
        $this->assertSame('howto.md', $res[0]['source']);
    }

    public function test_search_excludes_the_readme_and_unrelated_queries(): void
    {
        $kb = new KnowledgeBase($this->dir);

        // README is meta → never a result even on a matching term.
        foreach ($kb->search('οδηγίες') as $r) {
            $this->assertNotSame('README.md', $r['source']);
        }

        // No match → empty, so the tool can emit the «ρώτα λογιστή» signal.
        $this->assertSame([], $kb->search('κρυπτονομίσματα blockchain'));
    }

    public function test_search_is_accent_insensitive(): void
    {
        // «οφειλες» (no accents) must still hit «οφειλές».
        $res = (new KnowledgeBase($this->dir))->search('οφειλες');
        $this->assertNotEmpty($res);
        $this->assertSame('Πού βλέπω οφειλές;', $res[0]['heading']);
    }

    public function test_two_char_domain_terms_search_and_stopwords_are_ignored(): void
    {
        file_put_contents($this->dir.'/domain.md',
            "# Δελτίο Αποστολής\nΓια διακίνηση εξοπλισμού χρησιμοποίησε ΔΑ, όχι τιμολόγιο.\n");
        $kb = new KnowledgeBase($this->dir);

        // «ΔΑ» (2 chars) is a real domain term — it must be searchable.
        $res = $kb->search('ΔΑ');
        $this->assertNotEmpty($res);
        $this->assertSame('Δελτίο Αποστολής', $res[0]['heading']);

        // A query of only Greek function words yields no terms → no results (not noise).
        $this->assertSame([], $kb->search('και για του στο'));
    }

    public function test_tool_grounds_a_hit_and_flags_a_miss_on_the_shipped_kb(): void
    {
        $tenant = Company::create([
            'name' => 'KB OE', 'slug' => 'kb-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $tool = new KnowledgeSearchTool;

        // Hit against the real docs/assistant-kb that ships with the repo.
        $hit = $tool->run($tenant, ['query' => 'πώς κόβω πιστωτικό']);
        $this->assertTrue($hit['found']);
        $this->assertNotEmpty($hit['results']);
        $this->assertStringContainsString('ΑΥΣΤΗΡΑ', $hit['grounding']);

        // Miss → found=false and a «ρώτα λογιστή / μην μαντέψεις» grounding.
        $miss = $tool->run($tenant, ['query' => 'ζζζ ανύπαρκτο θέμα ξψω']);
        $this->assertFalse($miss['found']);
        $this->assertSame([], $miss['results']);
        $this->assertStringContainsString('λογιστή', $miss['grounding']);

        // Empty query → structured error, no crash.
        $this->assertArrayHasKey('error', $tool->run($tenant, ['query' => '  ']));
    }
}
