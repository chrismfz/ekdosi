<?php

namespace App\Services\EInvoice;

use App\Contracts\EInvoiceProviderTransport;
use App\Contracts\EInvoiceSubmitter;
use App\Exceptions\EInvoice\ProviderTransportException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\MyDataRejected;
use App\Services\Whmcs\WhmcsWritebackService;
use App\Support\EInvoice\FilingLog;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderIdentity;
use App\Support\EInvoice\ProviderIssueDateGuard;
use App\Support\EInvoice\ProviderResult;
use App\Support\MyData\CancellationMark;
use App\Support\Tenancy\TenantCoherence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Files an invoice through a certified ΥΠΑΗΕΣ provider (P2). It reuses the SAME
 * canonical AADE payload as MyDataSubmitter (AadeInvoiceDocument) and hands the
 * XML to a provider transport, which submits to myDATA on the tenant's behalf and
 * returns the MARK + authentication code + QR. The mydata_marks row stays the
 * legal source of truth (action PROVIDER_INSERT / PROVIDER_CANCEL, + the provider
 * audit columns); the invoice mirror columns sync exactly as in the direct path.
 *
 * ONE submitter for ALL GR providers — the per-provider difference lives entirely
 * in the injected EInvoiceProviderTransport (resolved from the registry by the
 * factory). See docs/paroxos/implementation-plan.md §2.3 / §14.
 *
 * Idempotency / double-filing (§14):
 *   - pre-submit state guards refuse a VALID / CANCELLED / unknown-state invoice;
 *   - on an AMBIGUOUS transport failure (timeout/connection — the provider MIGHT
 *     have filed), we status-check by invoice coordinates and ADOPT an existing
 *     MARK instead of blindly re-filing;
 *   - a duplicate INSERT MARK for the same invoice is de-duped on persist.
 *
 * WHMCS write-back fires on filing + cancel (parity with MyDataSubmitter, keyed on
 * whmcs_pending_id — no-op for non-WHMCS). The ONE remaining parity follow-up is
 * auto-email on VALID (a best-effort UX nicety MyDataSubmitter does); a provider
 * tenant gets it in a later pass.
 */
class GrProviderSubmitter implements EInvoiceSubmitter
{
    public function __construct(
        private readonly Company $tenant,
        private readonly EInvoiceProviderTransport $transport,
    ) {}

    public function submit(Invoice $invoice): MyDataMark
    {
        // MYD-022: fail closed BEFORE payload construction, audit writes or any
        // outbound request. Especially here — InvoSignDocument reads its issuer
        // fields from $invoice->company while the credentials come from $this->tenant,
        // so a mismatched call yields ONE payload asserting TWO different issuers.
        TenantCoherence::assertInvoice($this->tenant, $invoice);

        // MYD-021: the same single-flight lock the direct and delivery paths take.
        // This was the last filing entry point without one, so a double-click or an
        // overlapping auto-issue could let two requests both pass
        // assertNotAlreadyFiled() and both POST — two MARKs for one (series, ΑΑ).
        // The SAME lock key as MyDataSubmitter on purpose: a tenant that switches
        // channel mid-flight must still serialise on the invoice, not race itself.
        //
        // The lock closes the concurrent case. It does NOT make the provider path
        // durably exactly-once — a hard kill still leaves no marker here, because
        // the pre-POST marker needs provider-side verification to be recoverable at
        // all. That is PROV-001; see docs/BACKLOG.md.
        $lock = Cache::lock('mydata-submit:'.$invoice->getKey(), 120);
        if (! $lock->get()) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode}: μια υποβολή είναι ήδη σε εξέλιξη — "
                .'περίμενε να ολοκληρωθεί πριν ξαναδοκιμάσεις.'
            );
        }

        try {
            // Re-read FRESH under the lock: a submit that just finished on another
            // worker may have flipped mydata_state, and the in-memory $invoice would
            // be stale — assertNotAlreadyFiled must see the committed state.
            $invoice->refresh();

            return $this->performSubmit($invoice);
        } finally {
            $lock->release();
        }
    }

    private function performSubmit(Invoice $invoice): MyDataMark
    {
        $this->assertNotAlreadyFiled($invoice);
        // PROV-020 (auto): under the two-dates model the LEGAL issue date is the moment
        // of issue — i.e. now, when we press «Αποστολή» — not when the draft was prepared
        // (that stays on created_at). The provider also REQUIRES IssueDate = today
        // (InvoSign 238). So stamp issued_at to today HERE instead of blocking a stale
        // draft and making the operator fix it by hand. Fresh issue only (assertNotAlready-
        // Filed above rejects a re-send/recovery), on the lock-fresh invoice.
        $this->stampIssuedToday($invoice);
        // Safety net: the stamp makes this trivially pass, but keep the guard so any future
        // path reaching here without stamping still cannot backdate a provider call.
        ProviderIssueDateGuard::assertIssuedToday($invoice->issued_at, (string) $invoice->invcode);

        $document = new AadeInvoiceDocument($this->tenant);
        $payload = $document->build($invoice);
        $xml = $document->toXml($payload);
        $credentials = ProviderCredentials::fromCompany($this->tenant);

        // Wall-clock from just before the outbound send to the success log below.
        $startedAt = microtime(true);

        try {
            $result = $this->transport->send($invoice, $xml, $credentials);
        } catch (Throwable $e) {
            // Ambiguous: the provider may or may not have filed. NEVER blind-retry
            // (§14.4) — status-check by invoice coordinates first and adopt a MARK
            // if one exists, otherwise record the failure and surface it.
            $adopted = $this->recoverViaStatusCheck($invoice, $xml, $credentials, $e);
            if ($adopted !== null) {
                $this->syncWhmcsFiled($invoice, $adopted);

                return $adopted;
            }

            $this->logFailure($invoice, 'transport', $e);
            // Forensic trail even on a hard transport failure: the doc may have filed
            // despite the lost response, so record WHAT WE TRIED TO SEND (the exact
            // augmented payload when the transport reports it, else the AADE core) +
            // the error — so an operator can find/cancel it manually.
            $attempted = ($e instanceof ProviderTransportException && $e->attemptedPayload !== null)
                ? $e->attemptedPayload
                : $xml;
            $this->recordTransportFailure($invoice, 'PROVIDER_FAILED', $attempted, $e->getMessage());

            throw new RuntimeException(
                'E-invoice provider unreachable / submission failed: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (! $result->success) {
            $this->recordRejection($invoice, $xml, $result);
            throw new MyDataRejected(
                'E-invoice provider rejected the submission: '.$result->errorMessage(),
                $xml,
                $result->raw ?? ''
            );
        }

        $mark = $this->persistSuccess($invoice, $xml, $result);

        // OBS-001: one structured success line so `log_tail --contains=<invcode>`
        // finds a provider filing that WORKED, not just the ones that failed. Only
        // on a FRESH insert (persistSuccess adopts an existing row idempotently on
        // a retry). The recovery/adopt path returns earlier and logs its own
        // invcode-bearing line.
        if ($mark->wasRecentlyCreated) {
            FilingLog::filed($invoice, (string) $mark->mark, $this->transport->key(), $startedAt);
            $this->warnIfLowProviderQuota($invoice, $mark);
        }

        $this->syncWhmcsFiled($invoice, $mark);

        return $mark;
    }

    /**
     * Parity with MyDataSubmitter (H1): close the WHMCS-inbox loop on a provider
     * filing too. Write-back is keyed on invoices.whmcs_pending_id — INDEPENDENT
     * of the e-invoice provider — so a provider tenant that staged an invoice from
     * the WHMCS inbox must still flip the pending row drafted→filed and push the
     * MARK back to the bridge. No-op for non-WHMCS invoices; never throws (a
     * write-back hiccup must not mask a successful filing).
     */
    private function syncWhmcsFiled(Invoice $invoice, MyDataMark $mark): void
    {
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, $mark->mark);
    }

    public function cancel(Invoice $invoice, string $reason = ''): MyDataMark
    {
        // MYD-022: fail closed BEFORE payload construction, audit writes or any
        // outbound request. Especially here — InvoSignDocument reads its issuer
        // fields from $invoice->company while the credentials come from $this->tenant,
        // so a mismatched call yields ONE payload asserting TWO different issuers.
        TenantCoherence::assertInvoice($this->tenant, $invoice);

        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is already cancelled at myDATA (state=CANCELLED). Refusing to double-cancel."
            );
        }

        // Provider cancellation is possible ONLY for 9.3 δελτία αποστολής: every
        // provider's cancel endpoint is CancelDeliveryNote, which AADE accepts only
        // for type 9.3 (InvoSign returns [283] otherwise — sandbox-observed
        // 2026-07-07), and a provider-posted invoice can't be cancelled directly at
        // AADE either ([249] "posted by provider"). For any other type the reversal
        // is a credit note (5.1). The UI already routes this (ViewInvoice:
        // cancel_at_mydata is 9.3-only on a provider channel; non-9.3 → «Ακύρωση
        // μέσω πιστωτικού»); guard it at the service level too so a non-UI caller
        // (automation / bulk / API) gets a clear refusal instead of the opaque
        // provider [283]. (Holds even for a legacy direct INSERT on a migrated
        // gr-mydata→gr-provider tenant: the provider transport still can't cancel a
        // non-9.3.)
        $invoice->loadMissing('invoiceType');
        $type = (string) ($invoice->invoiceType?->mydata_type ?? '');
        if ($type !== '9.3') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} (τύπος ".($type !== '' ? $type : '—').') δεν ακυρώνεται μέσω παρόχου — '.
                'μόνο τα δελτία αποστολής 9.3 ακυρώνονται (CancelDeliveryNote). Για διόρθωση/αναίρεση '.
                'έκδοσε πιστωτικό τιμολόγιο (5.1) που συσχετίζεται με το αρχικό MARK.'
            );
        }

        // Read the MARK from the audit history, not the mirror column (same
        // reasoning as MyDataSubmitter::cancel). Accept both PROVIDER_INSERT and a
        // legacy direct INSERT — a tenant migrated gr-mydata→gr-provider mid-life
        // may cancel a directly-filed document through the provider (deliberate).
        $inserts = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('mydata_action', ['PROVIDER_INSERT', 'INSERT'])
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->get();

        if ($inserts->isEmpty()) {
            throw new RuntimeException(
                "Cannot cancel invoice {$invoice->invcode} — no INSERT MARK on file (never filed via the provider)."
            );
        }

        // M1: the §14.4 recovery can, in a partial-failure window, leave more than
        // one INSERT MARK for an invoice. Cancel the latest but surface the others
        // — they may be orphan filings needing manual reconciliation.
        if ($inserts->count() > 1) {
            Log::warning('Provider cancel: multiple INSERT MARKs found — cancelling latest only', [
                'invoice_id' => $invoice->id,
                'invcode' => $invoice->invcode,
                'marks' => $inserts->pluck('mark')->all(),
                'note' => 'Earlier MARKs may be orphan filings. Manual reconciliation required.',
            ]);
        }

        $mark = $inserts->first()->mark;

        $credentials = ProviderCredentials::fromCompany($this->tenant);

        try {
            $result = $this->transport->cancel((string) $mark, $credentials, $reason);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            $this->recordTransportFailure(
                $invoice, 'PROVIDER_CANCEL_FAILED',
                'Cancel MARK '.$mark.($reason !== '' ? " — reason: {$reason}" : ''),
                $e->getMessage(), (string) $mark,
            );
            throw new RuntimeException('E-invoice provider cancellation failed: '.$e->getMessage(), 0, $e);
        }

        if (! $result->success) {
            // Record a forensic PROVIDER_CANCEL_REJECTED row so the rejection (what
            // we asked + what the provider returned) is visible in the history —
            // otherwise a failed cancel leaves NO trace to debug.
            $this->recordCancelRejection($invoice, (string) $mark, $reason, $result);
            throw new RuntimeException('E-invoice provider rejected the cancellation: '.$result->errorMessage());
        }

        $audit = DB::transaction(function () use ($invoice, $mark, $reason, $result) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                // MYD-023: the two MARKs are different evidence and go in their own
                // columns. This used to be `$result->cancellationMark ?? $mark`,
                // which relabelled the ISSUE MARK as the cancellation proof
                // whenever the provider returned none — on the very path that
                // becomes mandatory. `mydata_marks.cancellation_mark` already
                // existed for the direct path; it was simply not used here.
                'mark' => (string) $mark,
                // '' is not evidence — see CancellationMark.
                'cancellation_mark' => CancellationMark::clean($result->cancellationMark),
                'mydata_action' => 'PROVIDER_CANCEL',
                'provider_key' => $this->transport->key(),
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            $invoice->forceFill([
                'mydata_state' => 'CANCELLED',
                'local_status' => 'cancelled',
            ])->save();

            return $audit;
        });

        // Reflect the cancellation on the WHMCS side (parity with MyDataSubmitter).
        // OUTSIDE the transaction — network call, must not hold a DB lock. No-op for
        // non-WHMCS invoices; never throws.
        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        return $audit;
    }

    public function testConnection(): bool
    {
        return $this->transport->ping(ProviderCredentials::fromCompany($this->tenant));
    }

    // ---- internals ------------------------------------------------------

    /**
     * Same defense-in-depth as MyDataSubmitter::submit — never re-file an invoice
     * that's already VALID or CANCELLED at myDATA, and refuse any unrecognised state.
     */
    private function assertNotAlreadyFiled(Invoice $invoice): void
    {
        if ($invoice->mydata_state === 'VALID') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was already filed at myDATA under MARK ".
                ($invoice->mydata_mark ?: '?').'. Cancel and re-issue if a correction is needed.'
            );
        }
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was previously filed and CANCELLED at myDATA. ".
                'Issue a correction invoice (new code) instead of resubmitting.'
            );
        }
        if ($invoice->mydata_state !== null && $invoice->mydata_state !== '') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has an unrecognised mydata_state='{$invoice->mydata_state}'. ".
                'Refusing to submit — investigate before retrying.'
            );
        }
        // MYD-3 (AUDIT): mirror MyDataSubmitter — a locally-voided document
        // must never reach the provider/AADE.
        if ($invoice->local_status === 'cancelled') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is locally cancelled — refusing to file it. ".
                'Restore it first (Επαναφορά σε πρόχειρο → Οριστικοποίηση) if the cancellation was a mistake.'
            );
        }
    }

    /**
     * Stamp the legal issue date (issued_at) to today, Greece-local, at the moment
     * of issue — only when it isn't already today, so an already-today document is
     * not needlessly rewritten (no spurious audit entry). The internal «πότε φτιάχτηκε»
     * lives on created_at and is untouched. Runs under the submit lock on the
     * lock-fresh invoice (see submit()), so it commits before the payload is built.
     */
    private function stampIssuedToday(Invoice $invoice): void
    {
        // now() is app-tz; the payload's setIssueDate and this compare both resolve
        // «today» via Europe/Athens. They agree while APP_TIMEZONE=Europe/Athens (this
        // Greek app always runs that) — see docs/BACKLOG.md for the tz-unification note.
        $tz = ProviderIssueDateGuard::TZ;
        $today = now()->setTimezone($tz)->toDateString();

        if ($invoice->issued_at?->copy()->setTimezone($tz)->toDateString() === $today) {
            return;
        }

        $invoice->update(['issued_at' => now()]);
    }

    /**
     * §14.4 idempotency: a transport-level failure (timeout/connection) is
     * ambiguous. Ask the provider for the document's state by coordinates; if it
     * already has a MARK, the filing DID happen — adopt it (persist success) so a
     * retry can't double-file. Any error from the status-check itself is swallowed
     * (logged) — we fall back to surfacing the original failure to the operator.
     */
    private function recoverViaStatusCheck(
        Invoice $invoice,
        string $xml,
        ProviderCredentials $credentials,
        Throwable $original,
    ): ?MyDataMark {
        try {
            $status = $this->transport->status($invoice, $credentials);
        } catch (Throwable $e) {
            Log::info('Provider status-check after a failed send found nothing to adopt.', [
                'invoice_id' => $invoice->id,
                'invcode' => $invoice->invcode,
                'send_error' => $original->getMessage(),
                'status_error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $status->success || ($status->mark ?? '') === '') {
            return null;
        }

        Log::warning('Provider send failed but status-check found an existing MARK — adopting (avoided double-file).', [
            'invoice_id' => $invoice->id,
            'invcode' => $invoice->invcode,
            'mark' => $status->mark,
        ]);

        return $this->persistSuccess($invoice, $xml, $status, viaRecovery: true);
    }

    /**
     * PROV-009: warn when the provider account's remaining quota runs low. The count
     * arrives free on every issue response (InvoSign `remaining_invoices`), so this
     * needs no polling. A log line (surfaces via ops:health / log_tail); the running
     * count lives in the ProviderQuotaStats dashboard widget. Never throws — a filing
     * already succeeded.
     *
     * Fires only when this reading crosses into a WORSE band than the previous
     * reading for the same tenant+provider — not on every filing while already in the
     * same band, which would spam an identical line per filing and drown the signal.
     * Bands: ok (> threshold) / low (≤ threshold) / exhausted (≤ 0). Because
     * low→exhausted is its OWN crossing (band 1 → band 2), a gradual depletion still
     * escalates to the error line the first time it hits zero — the round-1 «skip if
     * previously ≤ threshold» guard swallowed exactly that transition.
     */
    private function warnIfLowProviderQuota(Invoice $invoice, MyDataMark $mark): void
    {
        $remaining = $mark->remaining_invoices;
        if ($remaining === null) {
            return; // provider didn't report a quota
        }

        $threshold = (int) config('ekdosi.einvoice.provider_low_quota_threshold', 50);
        $band = $this->quotaBand((int) $remaining, $threshold);
        if ($band === 0) {
            return; // still comfortably above the threshold
        }

        // Crossing check: compare against the previous reading for THIS tenant on THIS
        // provider (scoped by provider_key so a migrated gr-mydata→gr-provider tenant,
        // or a future second provider, compares like with like). Stay quiet only when
        // that reading was already in this band OR worse — i.e. we warned on the
        // earlier crossing. A strictly-worse move (ok→low, ok→exhausted, low→exhausted)
        // is a fresh crossing and speaks up.
        $previous = MyDataMark::query()
            ->where('company_id', $invoice->company_id)
            ->where('provider_key', $this->transport->key())
            ->whereNotNull('remaining_invoices')
            ->where('id', '<', $mark->id)
            ->latest('id')
            ->value('remaining_invoices');
        if ($previous !== null && $this->quotaBand((int) $previous, $threshold) >= $band) {
            return;
        }

        $context = [
            'company_id' => $invoice->company_id,
            'invcode' => $invoice->invcode,
            'provider' => $this->transport->key(),
            'remaining_invoices' => $remaining,
            'threshold' => $threshold,
        ];

        if ($band >= 2) {
            Log::error('E-invoice provider quota is EXHAUSTED', $context);
        } else {
            Log::warning('E-invoice provider quota is low', $context);
        }
    }

    /**
     * Severity band of a remaining-quota reading: 0 = ok (above the threshold),
     * 1 = low (≤ threshold, still > 0), 2 = exhausted (≤ 0). Kept a pure function so
     * the crossing check compares the current and previous readings on the same scale.
     */
    private function quotaBand(int $remaining, int $threshold): int
    {
        return match (true) {
            $remaining <= 0 => 2,
            $remaining <= $threshold => 1,
            default => 0,
        };
    }

    /**
     * Persist a successful provider filing: a PROVIDER_INSERT mydata_marks row
     * (with provider audit columns) + the invoice mirror-column sync. Idempotent
     * on (invoice, mark): a duplicate adopts the existing row.
     */
    private function persistSuccess(Invoice $invoice, string $xml, ProviderResult $result, bool $viaRecovery = false): MyDataMark
    {
        $mark = (string) $result->mark;

        // Defense in depth (any transport): never flip an invoice to VALID without a
        // real MARK — a "success" with no MARK is not a filing. Refuse loudly so the
        // operator sees it and the document stays re-fileable.
        if ($mark === '') {
            throw new RuntimeException(
                "E-invoice provider reported success but returned no MARK for invoice {$invoice->invcode}. ".
                'Refusing to mark VALID — investigate the provider response.'
            );
        }

        $existing = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'PROVIDER_INSERT')
            ->first();
        if ($existing) {
            // A row adopted via a lighter recovery/status response may have been
            // stored before the UID / auth code / QR were known (M2). If THIS
            // response now carries them, backfill the still-empty fields — never
            // overwrite a value we already have. Closes the "UID stays null forever
            // after a recovery-adopt" gap without a re-file.
            $backfill = array_filter([
                'uid' => blank($existing->uid) ? $result->uid : null,
                'authentication_code' => blank($existing->authentication_code) ? $result->authenticationCode : null,
                'invoice_url' => blank($existing->invoice_url) ? $result->qrUrl : null,
                // PROV-009: fill the operational evidence if the earlier (lighter)
                // response lacked it. Never overwrite a value we already stored.
                'remaining_invoices' => $existing->remaining_invoices === null ? $result->remainingInvoices : null,
                'reception_emails' => blank($existing->reception_emails) ? $result->receptionEmails : null,
            ], static fn ($v) => filled($v));
            if ($backfill !== []) {
                $existing->forceFill($backfill)->save();
            }

            return $existing;
        }

        // M2: a status-check (recovery) response is lighter than a send response —
        // it may carry only the MARK, not the QR/auth code. Never NULL-out a mirror
        // column we can't refresh; flag the adopted row so an operator knows the
        // audit is partial and a reconciliation pass should backfill it.
        $deliveryState = $result->deliveryState ?? ($viaRecovery ? 'ADOPTED' : null);

        // PROV-003: freeze the provider identity (name/site/AADE code/ΥΠΑΗΕΣ
        // licence) IN FORCE right now, so a later config/licence rotation can't
        // rewrite this document's printed evidence. Only freeze a COMPLETE identity
        // (a real licence): freezing an empty/partial one at issue would strand the
        // document with a blank licence forever (snapshot-wins), un-printable even
        // after the config is fixed. No snapshot → the reader falls back to the
        // current config identity, which is the recoverable state.
        $identity = ProviderIdentity::forKey($this->transport->key());
        $providerIdentity = ($identity !== null && $identity->licenceNo !== '')
            ? $identity->toArray()
            : null;

        return DB::transaction(function () use ($invoice, $xml, $result, $mark, $deliveryState, $providerIdentity) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'PROVIDER_INSERT',
                'provider_key' => $this->transport->key(),
                'provider_identity' => $providerIdentity,
                'authentication_code' => $result->authenticationCode,
                // PROV-003: persist the provider document UID (was parsed, then
                // dropped). Needed on the printed representation (A.1112/2025) and
                // as forensic evidence. Null on a lighter recovery response.
                'uid' => $result->uid,
                // PROV-009: operational evidence the provider returns on every issue.
                'remaining_invoices' => $result->remainingInvoices,
                'reception_emails' => $result->receptionEmails,
                'delivery_state' => $deliveryState,
                'invoice_url' => $result->qrUrl,
                // Store the ACTUAL payload the transport sent (e.g. InvoSign's
                // augmented xml_arxeio), falling back to the AADE core if the
                // transport didn't report one — so the history shows what the
                // provider really received, not just the pre-augment XML.
                'request' => $result->requestPayload ?? $xml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // MARK_AI0 trigger replacement — same mirror-column sync as the direct
            // path (forceFill: the submitter is the only legitimate writer). A
            // filed invoice is a live document → promote a draft to active. Coalesce
            // mydata_url so a lighter recovery response can't wipe an existing QR.
            // MYD-009: freeze the counterpart we actually filed in the SAME write —
            // 'mydata_sent' closes the live-customer fallback, so a legacy row's
            // reported party would otherwise become unreadable right here.
            $invoice->forceFill(array_merge($invoice->frozenPartyColumns(), [
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'local_status' => $invoice->local_status === 'draft' ? 'active' : $invoice->local_status,
                'mydata_mark' => $mark,
                'mydata_url' => $result->qrUrl ?? $invoice->mydata_url,
                'mydata_type' => $invoice->invoiceType?->mydata_type,
            ]))->save();

            return $audit;
        });
    }

    private function recordRejection(Invoice $invoice, string $xml, ProviderResult $result): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => null,
                'mydata_action' => 'PROVIDER_REJECTED',
                'provider_key' => $this->transport->key(),
                // The ACTUAL sent payload (augmented), not just the AADE core.
                'request' => $result->requestPayload ?? $xml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Provider: failed to persist PROVIDER_REJECTED audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forensic row for a TRANSPORT failure (timeout / non-2xx) on submit or cancel —
     * so the attempt (what we tried to send + the error) is in the history even when
     * no provider response came back. Never throws (best-effort audit).
     */
    private function recordTransportFailure(Invoice $invoice, string $action, string $request, string $error, ?string $mark = null): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => $action,
                'provider_key' => $this->transport->key(),
                'request' => $request,
                'response' => 'Transport error: '.$error,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Provider: failed to persist '.$action.' audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Forensic row for a REJECTED cancellation — so the failed cancel is debuggable. */
    private function recordCancelRejection(Invoice $invoice, string $mark, string $reason, ProviderResult $result): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'PROVIDER_CANCEL_REJECTED',
                'provider_key' => $this->transport->key(),
                'request' => 'Cancel MARK '.$mark.($reason !== '' ? " — reason: {$reason}" : ''),
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Provider: failed to persist PROVIDER_CANCEL_REJECTED audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logFailure(Invoice $invoice, string $kind, Throwable $e): void
    {
        Log::warning('E-invoice provider submission failure', [
            'company_id' => $this->tenant->getKey(),
            'invoice_id' => $invoice->id,
            'invcode' => $invoice->invcode,
            'provider_key' => $this->transport->key(),
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
