<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Services\Mycs\MycsRowRefresher;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adversarial review of #703, round 3 (MycsRowRefresher against the ingestor's frozen paths). Each test fails on the PR
 * as written. The common thread: the refresher holds a row because its snapshot differs from MyCS's op=invoice, then
 * calls the ingestor to «take MyCS's invoice as it is now», but on several paths the ingestor deliberately does not
 * replace the snapshot (held/frozen, consolidated, source conflict) or is never called (404). The difference never goes
 * away, so every later attempt holds the row again, and «Επαναφορά προς έλεγχο» does not help.
 */
class Adv703Round3Test extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://my.example.test/api/ekdosi';

    /** @return array<string, mixed> a real MyCS feed payload (the fixture made by MyCS's code), under another id */
    private function payload(int $id, int $fixtureId = 1): array
    {
        $payload = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/mycs/feed-invoices.json')), true)['invoices'])
            ->firstWhere('id', $fixtureId);
        $payload['id'] = $payload['invoiceid'] = $id;
        $payload['third_party']['whmcs_invoice_id'] = $id;
        $payload['mycs']['requested_at'] = null;
        $payload['mycs']['ignore_invoicing'] = false;

        return $payload;
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'adv703c-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mycs_enabled' => true, 'mycs_url' => 'https://my.example.test', 'mycs_secret' => 'a-mycs-shared-secret-of-forty-characters',
        ]);
    }

    private function fakeInvoice(array $payload): void
    {
        Http::fake([self::ENDPOINT => Http::response(['status' => 'ok', 'invoice' => $payload]), '*' => Http::response([], 500)]);
    }

    /** What «Επαναφορά προς έλεγχο» (re_stage) does. */
    private function release(PendingWhmcsInvoice $row): PendingWhmcsInvoice
    {
        $row->refresh()->update(['status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 'rejected_reason' => null, 'hold_reason' => null]);

        return $row->refresh();
    }

    /**
     * R3-1 (P1). A source-conflict row can never be issued once its company is on MyCS. The ingestor decided the WHMCS
     * row #N and MyCS's #N are different invoices (another client: source_conflict, never overwritten); the operator
     * checked and released it. The refresher still compares the WHMCS snapshot with MyCS's #N (another client's invoice),
     * finds «πελάτης» changed, calls the ingestor (which, by design, keeps the WHMCS snapshot) and holds it again with a
     * false «Άλλαξε στη MyCS». Every «Δημιουργία Παραστατικού» after every release: held. The paid WHMCS invoice gets no
     * document at all.
     */
    public function test_a_released_source_conflict_row_is_not_held_forever_against_another_clients_invoice(): void
    {
        $tenant = $this->tenant();
        $whmcs = $this->payload(81);
        unset($whmcs['mycs'], $whmcs['third_party']);
        $whmcs['userid'] = 555;
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $whmcs)->row;
        $mycs = $this->payload(81); // MyCS's #81: another client's invoice
        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $mycs);
        $this->assertTrue((bool) $row->refresh()->source_conflict, 'precondition: the ingestor saw two invoices');

        $row = $this->release($row); // the operator checked: it is WHMCS's invoice of client 555
        $this->fakeInvoice($mycs);
        app(MycsRowRefresher::class)->check($row);
        $row = $this->release($row); // released again
        $why = app(MycsRowRefresher::class)->check($row);

        $this->assertNull($why, 'held again on every attempt, compared with another client\'s invoice: never issuable');
    }

    /**
     * R3-2 (P2). A WHMCS-era row of a MyCS company whose number MyCS does not have (WHMCS paid it past the last import,
     * the case §12 names) is held as «δεν υπάρχει πια στη MyCS» on every attempt: 404 skips the ingestor, nothing
     * changes, and releasing it does not help. «No longer» is also false: it never was in MyCS.
     */
    public function test_a_released_whmcs_row_that_mycs_never_had_is_not_held_forever(): void
    {
        $tenant = $this->tenant();
        $whmcs = $this->payload(82);
        unset($whmcs['mycs'], $whmcs['third_party']);
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $whmcs)->row;
        $this->assertSame(PendingWhmcsInvoice::SOURCE_WHMCS, $row->source, 'precondition');
        Http::fake([self::ENDPOINT => Http::response(['error' => 'invoice_not_found', 'invoice_id' => 82], 404), '*' => Http::response([], 500)]);

        app(MycsRowRefresher::class)->check($row);
        $row = $this->release($row);
        $why = app(MycsRowRefresher::class)->check($row);

        $this->assertNull($why, 'a paid WHMCS invoice blocked for good: held on every attempt after every release');
    }

    /**
     * R3-3 (P2). A multi-party MyCS row is always held (third parties), and «Διαχωρισμός σε προσχέδια» is offered on held
     * rows. When MyCS changed it, the refresher calls the ingestor to take MyCS's invoice, but a held row is audit-frozen:
     * the snapshot stays the old one. The refresher holds it «έλεγξέ το», the operator looks at the stale snapshot, splits
     * again, and is held again, forever (the docs: «the row takes MyCS's invoice as it is now»).
     */
    public function test_a_held_multi_party_row_takes_mycs_invoice_when_the_split_finds_it_changed(): void
    {
        $tenant = $this->tenant();
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(83, 2))->row;
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status, 'precondition: multi-party is held');
        $this->assertSame(PendingWhmcsInvoice::TP_MULTI, $row->third_party_state, 'precondition');

        $now = $this->payload(83, 2); // staff changed a line in MyCS
        $now['items']['item'][1]['amount'] = '7.00';
        $now['items']['item'][1]['total'] = '8.68';
        $now['mycs']['fingerprint'] = 'changed-in-mycs';
        $this->fakeInvoice($now);

        app(MycsRowRefresher::class)->check($row); // first split: held, «έλεγξέ το»

        $this->assertSame('7.00', $row->refresh()->payload['items']['item'][1]['amount'] ?? null,
            'the operator is told to check the change, but the row still shows the old line');
        $this->assertNull(app(MycsRowRefresher::class)->check($row), 'second split: held again for the same change');
    }

    /**
     * R3-4 (P2). A WHMCS mass-pay consolidated before the cut-over («Ενοποίηση»: a merged payload, in review) can never
     * be issued in a MyCS company: the merged snapshot never equals MyCS's raw invoice, the ingestor freezes a
     * consolidated row (touch), and the refresher holds it on every attempt. Released, held again; the «Ενοποίηση» action
     * is hidden once merged, so there is no way out.
     */
    public function test_a_consolidated_row_is_not_held_on_every_attempt(): void
    {
        $tenant = $this->tenant();
        $merged = $this->payload(84);
        unset($merged['mycs'], $merged['third_party']);
        $merged['ekdosi_consolidated_children'] = [841, 842];
        $row = PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'source' => PendingWhmcsInvoice::SOURCE_WHMCS, 'whmcs_invoice_id' => 84, 'whmcs_userid' => 100001,
            'payload' => $merged, 'match_reason' => PendingWhmcsInvoice::REASON_AFM, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
        $this->assertTrue($row->hasBeenConsolidated(), 'precondition');
        $this->fakeInvoice($this->payload(84));

        app(MycsRowRefresher::class)->check($row);
        $row = $this->release($row);
        $why = app(MycsRowRefresher::class)->check($row);

        $this->assertNull($why, 'the consolidated row is held again on every attempt: never issuable');
    }
}
