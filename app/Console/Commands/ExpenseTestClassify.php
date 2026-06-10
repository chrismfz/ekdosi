<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Services\MyData\ExpenseClassificationSubmitter;
use Illuminate\Console\Command;

/**
 * Build / validate / file the χαρακτηρισμός of an EXPENSE — the expense twin of
 * `mydata:test-submit`. Dry-run by default (prints the SendExpensesClassification
 * request XML, posts nothing); `--execute` files it at AADE for real.
 *
 *   php artisan expenses:test-classify <expenseId>            # dry-run, prints XML
 *   php artisan expenses:test-classify <expenseId> --execute  # REAL submission
 */
class ExpenseTestClassify extends Command
{
    protected $signature = 'expenses:test-classify
        {expense : Expense id (the numeric primary key)}
        {--execute : Actually POST to AADE (default is a dry-run that only prints the XML)}';

    protected $description = 'Build the expense-classification XML and (optionally) file it at myDATA. Dry-run by default.';

    public function handle(): int
    {
        $expense = Expense::query()->withoutGlobalScopes()->with('lines')->find($this->argument('expense'));
        if ($expense === null) {
            $this->error('Δεν βρέθηκε έξοδο με αυτό το id.');

            return self::FAILURE;
        }

        $tenant = $expense->company;
        if ($tenant === null) {
            $this->error('Το έξοδο δεν έχει εταιρία.');

            return self::FAILURE;
        }

        $submitter = new ExpenseClassificationSubmitter($tenant);

        // Dry-run preview: the exact XML submit() would POST.
        try {
            $this->line($submitter->requestXml($expense));
            $this->newLine();
        } catch (\Throwable $e) {
            $this->error('Αποτυχία δημιουργίας XML: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->warn('Dry-run: τίποτα δεν υποβλήθηκε. (Ο έλεγχος διαπιστευτηρίων myDATA γίνεται ΜΟΝΟ στο --execute.) Ξανατρέξε με --execute για πραγματική υποβολή.');

            return self::SUCCESS;
        }

        try {
            $mark = $submitter->submit($expense);
        } catch (\Throwable $e) {
            $this->error('✗ Υποβολή απέτυχε: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Υποβλήθηκε στην ΑΑΔΕ'.($mark !== '' ? ' — ΜΑΡΚ χαρακτηρισμού: '.$mark : '.'));

        return self::SUCCESS;
    }
}
