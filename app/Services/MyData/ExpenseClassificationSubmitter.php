<?php

namespace App\Services\MyData;

use App\Models\Expense;
use Firebed\AadeMyData\Enums\ExpenseClassificationCategory;
use Firebed\AadeMyData\Enums\ExpenseClassificationType;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\SendExpensesClassification;
use Firebed\AadeMyData\Models\ExpensesClassification;
use Firebed\AadeMyData\Models\ExpensesClassificationsDoc;
use Firebed\AadeMyData\Models\InvoiceExpensesClassification;
use Firebed\AadeMyData\Models\InvoicesExpensesClassificationDetail;
use Firebed\AadeMyData\Xml\ExpensesClassificationsDocWriter;
use RuntimeException;
use Throwable;

/**
 * Submits an EXPENSE's classification (χαρακτηρισμός εξόδου — δαπάνη / πάγιο /
 * εμπόρευμα) to AADE via firebed's SendExpensesClassification. The document
 * itself was filed by the SUPPLIER (we pulled it via RequestDocs into `expenses`);
 * here the BUYER tells AADE how it books it — the inbound mirror of MyDataSubmitter.
 *
 * The local «Χαρακτηρισμός» action sets a per-document E3 type + category2_x; the
 * «Χαρακτηρισμός ανά γραμμή» action sets them PER LINE (same supplier invoice may
 * mix εμπορεύματα + πάγια + δαπάνες). This submitter prefers each line's own
 * classification and falls back to the document header — so a uniformly-classified
 * doc and a mixed one both file correctly. Read-mostly: no sales money core touched.
 */
class ExpenseClassificationSubmitter
{
    /** @param callable|object|null $mockHandler Guzzle handler for tests (null = reset). */
    public function __construct(
        private readonly \App\Models\Company $tenant,
        private readonly mixed $mockHandler = null,
    ) {}

    /**
     * @return string  the AADE classification MARK
     *
     * @throws RuntimeException with an operator-facing Greek message
     */
    public function submit(Expense $expense): string
    {
        $doc = $this->document($expense);
        // Build the request XML ourselves (firebed's handle() doesn't expose it
        // cleanly — see the audit row below) so the legal trail is byte-exact.
        $requestXml = (new ExpensesClassificationsDocWriter)->asXML($doc);

        // A WRITE through the READ-oriented credential primer: for a gr-mydata
        // tenant read-mode == submit-mode, so this is correct; classifying one's
        // own expenses goes direct to myDATA (the provider channel only changes
        // who submits SALES). FirebedCredentials throws for a non-GR / Off tenant.
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        $action = new SendExpensesClassification;
        try {
            $response = $action->handle($doc);
        } catch (MyDataAuthenticationException $e) {
            throw new RuntimeException('Η myDATA απέρριψε τα διαπιστευτήρια. Έλεγξε Company → myDATA.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            throw new RuntimeException('Το endpoint της myDATA δεν είναι προσβάσιμο. Δοκίμασε ξανά.', 0, $e);
        } catch (MyDataException $e) {
            throw new RuntimeException('Αποτυχία υποβολής χαρακτηρισμού: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException('Απρόσμενη αποτυχία υποβολής χαρακτηρισμού.', 0, $e);
        }

        $first = $response->first();
        if ($first === null || ! $first->isSuccessful()) {
            throw new RuntimeException('Η ΑΑΔΕ απέρριψε τον χαρακτηρισμό: '.$this->describeErrors($first));
        }
        $classificationMark = (string) ($first->getClassificationMark() ?? '');

        // Legal audit trail: the byte-exact request + response of every myDATA
        // call on the expense side (twin of MyDataSubmitter's MyDataMark row).
        $expense->marks()->create([
            'company_id' => $expense->company_id,
            'mydata_action' => 'SendExpensesClassification',
            'mark' => $classificationMark ?: null,
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
            'request' => $requestXml ?: null,
            // firebed quirk: SendExpensesClassification::handle() leaves
            // responseDom null and parks the RESPONSE reader's DOM in requestDom —
            // so getRequestDom() here is actually the response XML.
            'response' => $action->getRequestDom()?->saveXML() ?: null,
        ]);

        $expense->forceFill(['classification_state' => 'submitted'])->save();

        return $classificationMark;
    }

    /** The request XML that submit() would POST — for the dry-run command. */
    public function requestXml(Expense $expense): string
    {
        return (new ExpensesClassificationsDocWriter)->asXML($this->document($expense));
    }

    private function document(Expense $expense): ExpensesClassificationsDoc
    {
        $mark = trim((string) $expense->mydata_mark);
        if ($mark === '') {
            throw new RuntimeException('Το έξοδο δεν έχει ΜΑΡΚ myDATA — μόνο παραστατικά που τραβήχτηκαν από την ΑΑΔΕ χαρακτηρίζονται.');
        }

        $expense->loadMissing('lines');
        if ($expense->lines->isEmpty()) {
            throw new RuntimeException('Το έξοδο δεν έχει γραμμές για χαρακτηρισμό.');
        }

        return new ExpensesClassificationsDoc($this->build($expense));
    }

    private function build(Expense $expense): InvoiceExpensesClassification
    {
        // Document-level fallback for lines without their own classification.
        $headerType = ExpenseClassificationType::tryFrom((string) $expense->classification_type);
        $headerCategory = ExpenseClassificationCategory::tryFrom((string) $expense->classification_category);

        $details = [];
        foreach ($expense->lines->values() as $i => $line) {
            $lineNo = (int) ($line->line_number ?: $i + 1);

            // Per-line classification wins; fall back to the document header.
            $type = ExpenseClassificationType::tryFrom((string) $line->classification_type) ?? $headerType;
            $category = ExpenseClassificationCategory::tryFrom((string) $line->classification_category) ?? $headerCategory;
            if ($type === null || $category === null) {
                throw new RuntimeException("Η γραμμή #{$lineNo} δεν έχει χαρακτηρισμό (τύπο/κατηγορία) ούτε στη γραμμή ούτε στην κεφαλίδα.");
            }

            $classification = new ExpensesClassification([
                'classificationType' => $type,
                'classificationCategory' => $category,
                'amount' => (float) $line->net_value,
            ]);

            $details[] = new InvoicesExpensesClassificationDetail([
                'lineNumber' => $lineNo,
                'expensesClassificationDetailData' => [$classification],
            ]);
        }

        $invoice = new InvoiceExpensesClassification;
        $invoice->setInvoiceMark($expense->mydata_mark);
        $invoice->setInvoicesExpensesClassificationDetails($details);

        return $invoice;
    }

    private function describeErrors(?object $response): string
    {
        if ($response === null || ! method_exists($response, 'getErrors')) {
            return $response?->getStatusCode() ?? 'unknown';
        }
        $errors = $response->getErrors();
        if ($errors === null) {
            return $response->getStatusCode() ?? 'unknown';
        }
        $messages = [];
        foreach ($errors as $e) {
            $code = method_exists($e, 'getCode') ? $e->getCode() : null;
            $msg = method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e;
            $messages[] = $code ? "[{$code}] {$msg}" : $msg;
        }

        return implode('; ', $messages) ?: ($response->getStatusCode() ?? 'unknown');
    }
}
