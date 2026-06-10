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
 * The local `classify` action sets the per-document E3 type + category2_x; this
 * applies that single classification to every line (each carrying its own net
 * amount) — the common case where a whole invoice is one category. Per-line
 * classification is a follow-up. Read-mostly: we don't touch the sales money core.
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
        $mark = trim((string) $expense->mydata_mark);
        if ($mark === '') {
            throw new RuntimeException('Το έξοδο δεν έχει ΜΑΡΚ myDATA — μόνο παραστατικά που τραβήχτηκαν από την ΑΑΔΕ χαρακτηρίζονται.');
        }
        if (blank($expense->classification_type) || blank($expense->classification_category)) {
            throw new RuntimeException('Όρισε πρώτα τύπο (E3) + κατηγορία χαρακτηρισμού (π.χ. εμπορεύματα/πάγια/δαπάνες).');
        }

        $expense->loadMissing('lines');
        if ($expense->lines->isEmpty()) {
            throw new RuntimeException('Το έξοδο δεν έχει γραμμές για χαρακτηρισμό.');
        }

        $doc = new ExpensesClassificationsDoc($this->build($expense));
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
            'date' => now(),
            'request' => $requestXml ?: null,
            // firebed quirk: SendExpensesClassification::handle() leaves
            // responseDom null and parks the RESPONSE reader's DOM in requestDom —
            // so getRequestDom() here is actually the response XML.
            'response' => $action->getRequestDom()?->saveXML() ?: null,
        ]);

        $expense->forceFill(['classification_state' => 'submitted'])->save();

        return $classificationMark;
    }

    private function build(Expense $expense): InvoiceExpensesClassification
    {
        $type = ExpenseClassificationType::tryFrom((string) $expense->classification_type)
            ?? throw new RuntimeException("Άγνωστος τύπος χαρακτηρισμού «{$expense->classification_type}».");
        $category = ExpenseClassificationCategory::tryFrom((string) $expense->classification_category)
            ?? throw new RuntimeException("Άγνωστη κατηγορία χαρακτηρισμού «{$expense->classification_category}».");

        $details = [];
        foreach ($expense->lines->values() as $i => $line) {
            $classification = new ExpensesClassification([
                'classificationType' => $type,
                'classificationCategory' => $category,
                'amount' => (float) $line->net_value,
            ]);

            $details[] = new InvoicesExpensesClassificationDetail([
                'lineNumber' => (int) ($line->line_number ?: $i + 1),
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
