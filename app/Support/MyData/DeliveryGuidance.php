<?php

namespace App\Support\MyData;

/**
 * The operator-facing guidance for Παραστατικά Διακίνησης — the «τι / πώς / γιατί»
 * an operator needs WITHOUT reading §8.14, every τροπολογία, or the AADE
 * e-transport spec. We encode the law ONCE here; the operator picks a
 * plain-Greek scenario and we map it to the right (allowed) myDATA code.
 *
 * SINGLE HOME on purpose: when the rules change (a move purpose gets blocked, a
 * mandatory field is added) this is the one file to edit — the Filament form
 * just reads `scenarioOptions()` / `fieldHelp()` / `INTRO`.
 *
 * Every scenario's `move_purpose` MUST be a NON-blocked §8.14 code
 * (DeliveryCodes::isMovePurposeAllowed) — guarded by DeliveryGuidanceTest.
 */
class DeliveryGuidance
{
    /**
     * «Τι θέλω να κάνω;» — the first thing the operator picks. Each maps to an
     * allowed §8.14 move purpose + sensible defaults, so they never face the raw
     * 20-code list. `other_title` pre-fills `other_move_purpose_title` for the
     * generic «Λοιπές» code 19.
     *
     * @var array<string, array{label: string, move_purpose: int, other_title?: string, hint: string}>
     */
    public const SCENARIOS = [
        'sale' => [
            'label' => 'Στέλνω εμπόρευμα σε πελάτη (πώληση)',
            'move_purpose' => 1, // Πώληση
            'hint' => 'Το αγαθό πωλείται και αποστέλλεται. Αν εκδίδεις και τιμολόγιο, σκέψου «Τιμολόγιο-Δελτίο Αποστολής».',
        ],
        'internal' => [
            'label' => 'Μεταφέρω δικό μου εξοπλισμό/εμπόρευμα σε ΔΙΚΗ ΜΟΥ εγκατάσταση/αποθήκη',
            'move_purpose' => 8, // Ενδοδιακίνηση
            'hint' => 'Ενδοδιακίνηση — μεταξύ ΔΗΛΩΜΕΝΩΝ εγκαταστάσεών σου (π.χ. ένα colocation rack που έχεις δηλώσει ως υποκατάστημα). Δεν είναι πώληση. (Το «Διακίνηση Παγίων» δεν δηλώνεται πλέον — η Ενδοδιακίνηση το καλύπτει.) Αν ο χώρος ΔΕΝ είναι δηλωμένος, δες «Colocation σε τρίτο».',
        ],
        'colocation' => [
            'label' => 'Στέλνω εξοπλισμό μου σε datacenter/χώρο τρίτου (colocation, ΜΗ δηλωμένο)',
            'move_purpose' => 14, // Αποθήκευση σε Τρίτους
            'hint' => 'Ο εξοπλισμός σου φιλοξενείται σε χώρο τρίτου που ΔΕΝ είναι δηλωμένο υποκατάστημά σου (π.χ. rack στο datacenter Synapsecom). Αν το έχεις δηλώσει ως εγκατάσταση, χρησιμοποίησε «Ενδοδιακίνηση». Γκρίζα ζώνη — επιβεβαίωσε με λογιστή.',
        ],
        'repair' => [
            'label' => 'Στέλνω κάτι για σέρβις / επισκευή',
            'move_purpose' => 7, // Επεξεργασία-Συναρμολόγηση-Αποσυναρμολόγηση
            'hint' => 'Αποστολή για επισκευή/επεξεργασία σε τρίτο (π.χ. στη Nexon για service). Όταν επιστραφεί, το δελτίο της επιστροφής το κόβει ο τρίτος για το δικό του σκέλος.',
        ],
        'return' => [
            'label' => 'Επιστροφή σε προμηθευτή',
            'move_purpose' => 5, // Επιστροφή
            'hint' => 'Επιστρέφεις αγαθό στον προμηθευτή/αποστολέα.',
        ],
        'sample' => [
            'label' => 'Δείγμα ή έκθεση',
            'move_purpose' => 3, // Δειγματισμός
            'hint' => 'Αποστολή δείγματος (3) ή για έκθεση. Δεν υπάρχει πώληση.',
        ],
        'other' => [
            'label' => 'Άλλο',
            'move_purpose' => 19, // Λοιπές Διακινήσεις
            'hint' => 'Οποιαδήποτε άλλη κίνηση — συμπλήρωσε έναν σύντομο τίτλο σκοπού.',
        ],
    ];

    /** @return array<string, string> key => label, for the scenario picker. */
    public static function scenarioOptions(): array
    {
        return array_map(fn (array $s) => $s['label'], self::SCENARIOS);
    }

    /**
     * @return array{label: string, move_purpose: int, other_title?: string, hint: string}|null
     */
    public static function scenario(string $key): ?array
    {
        return self::SCENARIOS[$key] ?? null;
    }

    /**
     * «τι / πώς / γιατί» per field — fed to the form's helperText so the meaning
     * is inline, not in a manual.
     *
     * @var array<string, string>
     */
    public const FIELD_HELP = [
        'scenario' => 'Διάλεξε τι κάνεις — συμπληρώνουμε αυτόματα τον σωστό κωδικό σκοπού της ΑΑΔΕ.',
        'move_purpose' => 'Γιατί κινείται το αγαθό (σκοπός διακίνησης). Καθορίζει πώς το βλέπει η ΑΑΔΕ. Οι κωδικοί που δεν γίνονται δεκτοί πλέον (π.χ. «Διακίνηση Παγίων») δεν εμφανίζονται. Το σενάριο βάζει έναν προτεινόμενο κωδικό — μπορείς πάντα να τον αλλάξεις.',
        'other_move_purpose_title' => 'Σύντομη περιγραφή του σκοπού — υποχρεωτική όταν ο σκοπός είναι «Λοιπές Διακινήσεις».',
        'customer' => 'Ο παραλήπτης. Άφησέ το κενό για ενδοδιακίνηση (μετακίνηση μέσα στην επιχείρησή σου).',
        'loading_address' => 'Από πού ΦΕΥΓΕΙ το αγαθό. Υποχρεωτικό για δελτίο αποστολής.',
        'delivery_address' => 'Πού ΠΑΕΙ το αγαθό. Υποχρεωτικό για δελτίο αποστολής.',
        'dispatch_at' => 'Πότε ξεκινά η διακίνηση (ημ/νία & ώρα έναρξης).',
        'vehicle_number' => 'Πινακίδα του οχήματος που μεταφέρει.',
        'carrier_afm' => 'ΑΦΜ του μεταφορέα — αν μεταφέρεις εσύ, βάλε το δικό σου.',
        'transport_type' => 'Με τι μεταφέρεται (φορτηγό, πλοίο, courier…).',
        'third_party_collection' => 'Σημείωσέ το αν το αγαθό το παραλαμβάνει τρίτος (π.χ. courier) από τις εγκαταστάσεις σου.',
    ];

    public static function fieldHelp(string $field): ?string
    {
        return self::FIELD_HELP[$field] ?? null;
    }

    /**
     * Top-of-page primer: what a delivery note is, when you need it, the flow.
     */
    public const INTRO =
        'Το Δελτίο Αποστολής συνοδεύει τη ΦΥΣΙΚΗ μετακίνηση αγαθών (πώληση, μεταφορά σε άλλη '
        .'αποθήκη, αποστολή για σέρβις, επιστροφή…). Διάλεξε «τι κάνεις» και συμπληρώνουμε τον '
        .'σωστό κωδικό. Ροή: Έκδοση → Έναρξη διακίνησης → Παράδοση. Δεν αφορά τιμολόγηση/ΦΠΑ — '
        .'το δελτίο δεν έχει αξία/φόρο. Σε αλυσίδα διακίνησης (π.χ. εσύ → συνεργείο → πελάτης), '
        .'ΚΑΘΕ εμπλεκόμενος κόβει ΤΟ ΔΙΚΟ ΤΟΥ δελτίο για το σκέλος που ελέγχει.';
}
