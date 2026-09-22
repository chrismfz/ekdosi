<?php

namespace App\Services\Customers;

/**
 * What a customer merge moved (or would move, from MergeCustomers::preview()).
 */
final class MergeCustomersResult
{
    /** Greek labels for the tables the operator sees. */
    private const TABLE_LABELS = [
        'invoices' => 'παραστατικά',
        'payments' => 'πληρωμές',
        'quotes' => 'προσφορές',
        'customer_contacts' => 'επαφές',
        'delivery_notes' => 'δελτία αποστολής',
        'service_contracts' => 'συμβόλαια υπηρεσιών',
        'pending_whmcs_invoices' => 'εκκρεμή WHMCS',
        'ai_pending_actions' => 'εκκρεμείς ενέργειες AI',
        'cmr_notes' => 'CMR',
        'payment_intents' => 'πληρωμές πύλης',
        'domains' => 'domains',
        'tickets' => 'αιτήματα υποστήριξης',
        'invoice_types' => 'τύποι παραστατικών (προεπιλεγμένος πελάτης)',
        'customers' => 'συστάσεις πελατών',
        'leads' => 'leads',
        'notes' => 'σημειώσεις',
        'attachments' => 'συνημμένα',
        'taggables' => 'ετικέτες',
        'activity_log' => 'γραμμές ιστορικού',
    ];

    /**
     * @param  array<string, int>  $moves  table => rows moved
     * @param  array<string, array{keep: ?string, drop: ?string, column: string}>  $differences  label => the two values
     */
    public function __construct(
        public readonly int $keepId,
        public readonly string $keepName,
        public readonly int $dropId,
        public readonly string $dropName,
        public readonly array $moves,
        public readonly array $differences,
    ) {}

    public function totalMoved(): int
    {
        return array_sum($this->moves);
    }

    public static function label(string $table): string
    {
        return self::TABLE_LABELS[$table] ?? $table;
    }

    /** «3 παραστατικά, 1 πληρωμές, 2 σημειώσεις» */
    public function movesLabel(): string
    {
        $parts = [];
        foreach ($this->moves as $table => $count) {
            $parts[] = $count.' '.self::label($table);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}
