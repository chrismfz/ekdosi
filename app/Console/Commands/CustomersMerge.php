<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Customers\MergeCustomers;
use App\Services\Customers\MergeCustomersResult;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Συγχώνευση δύο πελατών που είναι το ΙΔΙΟ νομικό πρόσωπο.
 *
 *   php artisan customers:merge 512 536            # δείχνει τι θα γίνει, ρωτά
 *   php artisan customers:merge 512 536 --dry-run  # μόνο η πρoεπισκόπηση
 *   php artisan customers:merge 512 536 --keep=536 # ποιος επιβιώνει (default: ο «μεγαλύτερος»)
 *
 * Ο ΠΡΩΤΟΣ πελάτης είναι ο προτεινόμενος επιζών μόνο αν δεν δοθεί --keep· από
 * μόνο του το εργαλείο κρατά αυτόν που κουβαλά τα περισσότερα (παραστατικά,
 * πληρωμές…) και ισοπαλία λύνεται με το μικρότερο id. Όλα σε μία transaction·
 * ο χαμένος διαγράφεται ΟΡΙΣΤΙΚΑ και τα στοιχεία που διέφεραν μένουν ως
 * καρφιτσωμένη σημείωση στον επιζώντα.
 */
class CustomersMerge extends Command
{
    protected $signature = 'customers:merge
        {a : id του πρώτου πελάτη}
        {b : id του δεύτερου πελάτη}
        {--keep= : ποιο id επιβιώνει (default: αυτός με τα περισσότερα)}
        {--dry-run : μόνο προεπισκόπηση, καμία αλλαγή}
        {--force : χωρίς ερώτηση επιβεβαίωσης}';

    protected $description = 'Συγχωνεύει δύο πελάτες (ίδιο νομικό πρόσωπο) σε έναν — μεταφέρει παραστατικά/πληρωμές/κλπ.';

    public function handle(MergeCustomers $merger): int
    {
        $a = $this->findCustomer((int) $this->argument('a'));
        $b = $this->findCustomer((int) $this->argument('b'));
        if ($a === null || $b === null) {
            return self::INVALID;
        }

        // Which one survives: --keep, else the fuller row.
        if ($keepId = $this->option('keep')) {
            $keepId = (int) $keepId;
            if (! in_array($keepId, [$a->getKey(), $b->getKey()], true)) {
                $this->error('Το --keep πρέπει να είναι ένα από τα δύο ids.');

                return self::INVALID;
            }
            $keep = $keepId === (int) $a->getKey() ? $a : $b;
        } else {
            $keep = $merger->suggestKeeper($a, $b);
        }
        $drop = $keep->is($a) ? $b : $a;

        try {
            $preview = $merger->preview($keep, $drop);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($preview);

        if ($this->option('dry-run')) {
            $this->comment('Dry-run — τίποτα δεν άλλαξε.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Οριστική συγχώνευση: ο #{$preview->dropId} θα ΔΙΑΓΡΑΦΕΙ και όλα του θα περάσουν στον #{$preview->keepId}. Συνέχεια;",
            false,
        )) {
            $this->comment('Ακυρώθηκε.');

            return self::SUCCESS;
        }

        try {
            $result = $merger($keep, $drop);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("✓ Συγχωνεύτηκαν: ο #{$result->dropId} «{$result->dropName}» πέρασε στον #{$result->keepId} «{$result->keepName}».");
        $this->line('  Μεταφέρθηκαν: '.$result->movesLabel().'.');
        $this->line('  Τα στοιχεία που διέφεραν καταγράφηκαν ως σημείωση στον πελάτη.');

        return self::SUCCESS;
    }

    private function findCustomer(int $id): ?Customer
    {
        $customer = Customer::query()->withTrashed()->whereKey($id)->first();
        if ($customer === null) {
            $this->error("Δεν βρέθηκε πελάτης #{$id}.");
        }

        return $customer;
    }

    private function report(MergeCustomersResult $preview): void
    {
        $this->newLine();
        $this->line("  ΚΡΑΤΑΜΕ:      #{$preview->keepId} «{$preview->keepName}»");
        $this->line("  ΔΙΑΓΡΑΦΕΤΑΙ:  #{$preview->dropId} «{$preview->dropName}»");
        $this->newLine();

        if ($preview->moves === []) {
            $this->line('  Δεν κρέμεται τίποτα από τον προς διαγραφή.');
        } else {
            $rows = [];
            foreach ($preview->moves as $table => $count) {
                $rows[] = [MergeCustomersResult::label($table), $count];
            }
            $this->table(['Μεταφέρονται', 'Πλήθος'], $rows);
        }

        if ($preview->differences !== []) {
            $rows = [];
            foreach ($preview->differences as $label => $pair) {
                $rows[] = [$label, $pair['keep'] ?? '—', $pair['drop'] ?? '—'];
            }
            $this->table(['Πεδίο', 'Κρατάμε (#'.$preview->keepId.')', 'Χάνεται (#'.$preview->dropId.') → σημείωση'], $rows);
        }
    }
}
