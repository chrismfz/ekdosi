<?php

namespace Tests\Feature\Whmcs;

use App\Console\Commands\WhmcsAutoIssue;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Support\Billing\MycsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Adversarial review of #703 (K3, «Send to Ekdosi» from MyCS). Each test fails on the PR as written.
 */
class Adv703Test extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(int $id, string $status = 'Paid', ?string $requestedAt = null, float $adjustments = 0.0): array
    {
        $payload = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/mycs/feed-invoices.json')), true)['invoices'])
            ->firstWhere('id', 1);
        $payload['id'] = $payload['invoiceid'] = $id;
        $payload['status'] = $status;
        $payload['mycs']['requested_at'] = $requestedAt;
        $payload['mycs']['ignore_invoicing'] = false;
        $payload['mycs']['adjustments'] = $adjustments;

        return $payload;
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'adv703-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mycs_enabled' => true, 'mycs_url' => 'https://my.example.test', 'mycs_secret' => 'a-mycs-shared-secret-of-forty-characters',
        ]);
    }

    private function griniaris(Company $tenant): Customer
    {
        return Customer::create(['company_id' => $tenant->id, 'name' => 'Γκρινιάρης', 'afm' => '099999999', 'needs_immediate_invoice' => true]);
    }

    /** @return Collection<int, PendingWhmcsInvoice> */
    private function autoIssueCandidates(Company $tenant): Collection
    {
        return collect((new ReflectionMethod(WhmcsAutoIssue::class, 'candidates'))->invoke(app(WhmcsAutoIssue::class), $tenant));
    }

    /**
     * ADV-1 (P1). A «Send to Ekdosi» that arrives while the row is HELD is dropped: a held row is audit-frozen, so the
     * ingestor only touches it and the stored payload never gets `requested_at`. When the operator later puts it back
     * to review («Επαναφορά προς έλεγχο»), whmcs:auto-issue sees no request and files it unattended for a γκρινιάρης
     * customer, the one thing K3 must never let happen.
     * Scenario: a paid invoice is staged, the operator holds it (e.g. waiting for ΑΦΜ), MyCS staff send it, the fetch
     * reads it from `requested`, the operator re-stages it, auto-issue runs before the next fetch.
     */
    public function test_a_request_on_a_held_row_is_not_lost_and_never_auto_issued(): void
    {
        $tenant = $this->tenant();
        $ingestor = app(WhmcsInvoiceIngestor::class);
        $row = $ingestor->ingest($tenant, $this->payload(11))->row;
        $row->update(['status' => PendingWhmcsInvoice::STATUS_HELD, 'hold_reason' => 'Αναμονή για ΑΦΜ']);

        $ingestor->ingest($tenant, $this->payload(11, 'Paid', '2026-10-27 10:00:00'));

        // The operator's «Επαναφορά προς έλεγχο» (WhmcsInboxTable::reStageAction).
        $row->fresh()->update(['status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 'rejected_reason' => null, 'hold_reason' => null,
            'customer_id' => $this->griniaris($tenant)->id]);

        $this->assertFalse($this->autoIssueCandidates($tenant)->contains('id', $row->id),
            'staff sent it while it was held; after re-stage whmcs:auto-issue would file it unattended');
    }

    /**
     * ADV-2 (P1). Reopening an archived row on a new request bypasses every hold the create/refresh paths apply: a MyCS
     * invoice with credit/debit notes (its total differs from its lines) comes back as pending_review and its
     * adjustment warning is dropped from the notes, while the same request staged fresh is HELD with the warning. The
     * operator gets a «ready» row whose lines build a document for the wrong amount.
     */
    public function test_a_reopened_request_keeps_the_adjustments_hold(): void
    {
        $tenant = $this->tenant();
        $ingestor = app(WhmcsInvoiceIngestor::class);
        $fresh = $ingestor->ingest($tenant, $this->payload(21, 'Paid', '2026-10-27 10:00:00', adjustments: -20.0))->row;
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $fresh->status, 'precondition: staged fresh it is held');

        $row = $ingestor->ingest($tenant, $this->payload(22, 'Paid', null, adjustments: -20.0))->row;
        $row->update(['status' => PendingWhmcsInvoice::STATUS_REJECTED, 'rejected_reason' => 'not now']);

        $ingestor->ingest($tenant, $this->payload(22, 'Paid', '2026-10-27 10:00:00', adjustments: -20.0));

        $row->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status, 'reopened as ready although its total differs from its lines');
        $this->assertStringContainsString('πιστωτικό/χρεωστικό', (string) $row->notes);
    }

    /**
     * ADV-3 (P2). The «Send to Ekdosi» label is only written when the row is created. A paid invoice already waiting in
     * the inbox (staged from `paid_unfiled` before staff sent it) is refreshed by the request with no note at all, so
     * the operator cannot tell MyCS staff asked for it (or that it must never go out unattended).
     */
    public function test_a_request_on_a_row_already_in_review_is_labelled(): void
    {
        $tenant = $this->tenant();
        $ingestor = app(WhmcsInvoiceIngestor::class);
        $row = $ingestor->ingest($tenant, $this->payload(31))->row;

        $ingestor->ingest($tenant, $this->payload(31, 'Paid', '2026-10-27 10:00:00'));

        $this->assertTrue(MycsPayload::requested($row->fresh()->payload), 'precondition: the payload has the request');
        $this->assertStringContainsString('Send to Ekdosi', (string) $row->fresh()->notes, 'requested, but no label');
    }

    /**
     * ADV-4 (P2). The reverse: staff withdraw the request («Withdraw from Ekdosi»); the next paid_unfiled payload has
     * no `requested_at`, so whmcs:auto-issue now files the row, while its note still says «Στάλθηκε από τη MyCS …
     * Δεν εκδίδεται ποτέ αυτόματα». The label promises the operator something the code no longer does.
     */
    public function test_a_withdrawn_request_does_not_keep_a_never_automatic_label(): void
    {
        $tenant = $this->tenant();
        $ingestor = app(WhmcsInvoiceIngestor::class);
        $row = $ingestor->ingest($tenant, $this->payload(41, 'Paid', '2026-10-27 10:00:00'))->row;

        $ingestor->ingest($tenant, $this->payload(41));
        $row->fresh()->update(['customer_id' => $this->griniaris($tenant)->id]);

        $this->assertTrue($this->autoIssueCandidates($tenant)->contains('id', $row->id), 'precondition: auto-issue picks it now');
        $this->assertStringNotContainsString('Δεν εκδίδεται ποτέ αυτόματα', (string) $row->fresh()->notes,
            'labelled «never automatic» while whmcs:auto-issue will file it');
    }

    /**
     * ADV-5 (P2). A row archived before the cut-over (source WHMCS) for the same invoice id and the same client is never
     * reopened by a MyCS request: the reopen requires source=mycs, so the request falls into the audit-frozen branch and
     * is silently dropped (only updated_at moves). MyCS keeps WHMCS's invoice ids and clients; staff asked for this
     * document and nothing in Ekdosi shows it.
     */
    public function test_a_request_reopens_a_row_archived_from_whmcs_for_the_same_client(): void
    {
        $tenant = $this->tenant();
        $ingestor = app(WhmcsInvoiceIngestor::class);
        $whmcs = $this->payload(51);
        unset($whmcs['mycs']);
        $row = $ingestor->ingest($tenant, $whmcs)->row;
        $this->assertSame(PendingWhmcsInvoice::SOURCE_WHMCS, $row->source, 'precondition');
        $row->update(['status' => PendingWhmcsInvoice::STATUS_REJECTED, 'rejected_reason' => 'pre cut-over']);

        $ingestor->ingest($tenant, $this->payload(51, 'Paid', '2026-10-27 10:00:00'));

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->fresh()->status, 'the request is dropped');
    }
}
