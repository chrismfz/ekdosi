<?php

declare(strict_types=1);

/**
 * Customer portal UI strings (Greek). Rendered by `resources/views/portal/*` and
 * the `portal-layout` component based on app()->getLocale() (set per customer user
 * by SetPortalLocale). The `en` twin lives beside this file — keep the keys in sync.
 *
 * Values here are byte-verbatim copies of the original hardcoded blade text.
 */
return [

    // Flash/status banners set by portal controllers (authenticated actions).
    'flash' => [
        'profile_saved' => 'Τα στοιχεία σου αποθηκεύτηκαν.',
        'password_changed' => 'Ο κωδικός σου άλλαξε.',
        'ticket_created' => 'Το αίτημα καταχωρήθηκε — θα ειδοποιηθείτε για την απάντηση.',
        'reply_sent' => 'Η απάντησή σας στάλθηκε.',
        'rating_thanks' => 'Ευχαριστούμε για την αξιολόγηση!',
    ],

    // Shared words/phrases reused across several portal pages.
    'common' => [
        'my_documents' => 'Τα παραστατικά μου',
        'my_statement' => 'Η καρτέλα μου',
        'my_requests' => 'Τα αιτήματά μου',
        'email' => 'Email',
        'save' => 'Αποθήκευση',
        'cancel' => 'Άκυρο',
        'afm' => 'ΑΦΜ',
        'via_me' => 'μέσω εμού',
        'date' => 'Ημ/νία',
        'document' => 'Παραστατικό',
        'type' => 'Τύπος',
        'status' => 'Κατάσταση',
        'mark' => 'ΜΑΡΚ',
        'download' => 'Λήψη',
        'on_mydata' => 'Στο myDATA',
        'issued' => 'Εκδόθηκε',
        'verify' => 'Επαλήθευση',
        'pdf' => 'PDF',
        'balance' => 'Υπόλοιπο',
        'charge' => 'Χρέωση',
        'credit' => 'Πίστωση',
        'payment' => 'Πληρωμή',
        'owed_balance' => 'Οφειλόμενο υπόλοιπο',
        'new_password' => 'Νέος κωδικός',
        'department' => 'Τμήμα',
        'subject' => 'Θέμα',
    ],

    // Layout: brand + top navigation + sign out.
    'nav' => [
        'brand' => 'Πύλη πελατών',
        'details' => 'Στοιχεία',
        'sign_out' => 'Αποσύνδεση',
    ],

    // «Τα παραστατικά μου» — issued documents list.
    'home' => [
        'welcome' => 'Καλωσήρθες, :name. Εδώ βλέπεις τα εκδοθέντα παραστατικά σου.',
        'no_documents_yet' => 'Δεν υπάρχουν παραστατικά ακόμη.',
        'recent_note' => 'Εμφανίζονται τα πιο πρόσφατα :count παραστατικά. Για παλαιότερα, επικοινώνησε μαζί μας.',
        'empty' => 'Δεν υπάρχουν παραστατικά διαθέσιμα για τον λογαριασμό σου ακόμη.',
    ],

    // «Η καρτέλα μου» — balance + transactions.
    'statement' => [
        'welcome' => 'Καλωσήρθες, :name. Εδώ βλέπεις το υπόλοιπο και τις κινήσεις σου.',
        'oldest_unpaid' => 'Παλαιότερο ανεξόφλητο: :days ημέρες',
        'credit_balance' => 'Πιστωτικό υπόλοιπο (υπέρ σου)',
        'no_movement' => 'Χωρίς κίνηση',
        'settled' => 'Εξοφλημένο',
        'pay' => 'Πλήρωσε',
        'no_activity_yet' => 'Καμία κίνηση ακόμη.',
        'movement' => 'Κίνηση',
        'badge_credit' => 'Πιστωτικό',
        'badge_refund' => 'Επιστροφή',
        'balance_note' => 'Το υπόλοιπο αφορά τα επί πιστώσει παραστατικά· οι τοις μετρητοίς πωλήσεις εξοφλούνται κατά την έκδοση.',
        'see_my_documents' => 'Δες τα παραστατικά μου',
        'empty' => 'Δεν υπάρχει καρτέλα διαθέσιμη για τον λογαριασμό σου ακόμη.',
    ],

    // «Τα στοιχεία μου» — account + password.
    'profile' => [
        'title' => 'Τα στοιχεία μου',
        'subtitle' => 'Διαχειρίσου τον λογαριασμό και τον κωδικό σου.',
        'account_details' => 'Στοιχεία λογαριασμού',
        'name' => 'Όνομα',
        'email_hint' => 'Το email είναι το αναγνωριστικό εισόδου — δεν αλλάζει από εδώ.',
        'phone' => 'Τηλέφωνο',
        'language' => 'Γλώσσα',
        'lang_el' => 'Ελληνικά',
        'lang_en' => 'English',
        'change_password' => 'Αλλαγή κωδικού',
        'current_password' => 'Τρέχων κωδικός',
        'confirm_new_password' => 'Επιβεβαίωση νέου κωδικού',
    ],

    // Customer sign-in.
    'login' => [
        'title' => 'Είσοδος πελατών',
        'subtitle' => 'Δες τα παραστατικά και τα στοιχεία σου.',
        'password' => 'Κωδικός',
        'remember' => 'Να με θυμάσαι',
        'forgot' => 'Ξέχασα τον κωδικό',
        'submit' => 'Είσοδος',
        'failed' => 'Λάθος email ή κωδικός.',
    ],

    // Forgot / reset password.
    'password' => [
        'title' => 'Ορισμός κωδικού',
        'forgot_heading' => 'Ξέχασες τον κωδικό;',
        'forgot_intro' => 'Δώσε το email σου και θα λάβεις σύνδεσμο για να ορίσεις κωδικό. Αν είσαι νέος χρήστης που μόλις προσκλήθηκε, από εδώ ορίζεις τον πρώτο σου κωδικό.',
        'honeypot' => 'Μην το συμπληρώσεις',
        'send_link' => 'Αποστολή συνδέσμου',
        'back_to_login' => 'Επιστροφή στην είσοδο',
        'reset_heading' => 'Όρισε νέο κωδικό',
        'reset_intro' => 'Διάλεξε έναν κωδικό για τον λογαριασμό σου.',
        'confirm_password' => 'Επιβεβαίωση κωδικού',
    ],

    // Payment: create / redirect / show.
    'payment' => [
        'no_method' => 'Δεν υπάρχει διαθέσιμος τρόπος πληρωμής αυτή τη στιγμή. Επικοινώνησε μαζί μας.',
        'what' => 'Τι πληρώνεις;',
        'whole_balance' => 'Όλο το υπόλοιπο (:amount)',
        'amount_field' => 'Ποσό (€)',
        'method' => 'Τρόπος πληρωμής',
        'continue' => 'Συνέχεια',
        'back_to_statement' => 'Επιστροφή στην καρτέλα',
        'redirect_title' => 'Ανακατεύθυνση στην πληρωμή',
        'redirect_intro' => 'Σε μεταφέρουμε με ασφάλεια στη σελίδα πληρωμής της τράπεζας για :amount. Αν δεν μεταφερθείς αυτόματα, πάτησε «Συνέχεια».',
        'continue_to_payment' => 'Συνέχεια στην πληρωμή',
        'cancel_back' => 'Άκυρο — επιστροφή στην καρτέλα',
        'instructions_title' => 'Οδηγίες πληρωμής',
        'settled' => 'Η πληρωμή καταχωρίστηκε. Ευχαριστούμε!',
        'follow_instructions' => 'Ακολούθησε τις οδηγίες για να ολοκληρώσεις την πληρωμή. Θα καταχωριστεί μόλις επιβεβαιωθεί.',
        'amount' => 'Ποσό',
        'reference' => 'Κωδικός αναφοράς',
        'details' => 'Στοιχεία πληρωμής',
        'mention_reference' => 'Στην αιτιολογία, ανάφερε τον κωδικό :reference.',
    ],

    // Support tickets: create / index / show.
    'tickets' => [
        'new' => 'Νέο αίτημα',
        'new_heading' => 'Νέο αίτημα υποστήριξης',
        'new_intro' => 'Περίγραψε το θέμα σου και θα σου απαντήσουμε το συντομότερο.',
        'no_department' => 'Δεν υπάρχει διαθέσιμο τμήμα υποστήριξης αυτή τη στιγμή. Δοκίμασε αργότερα.',
        'priority' => 'Προτεραιότητα',
        'priority_low' => 'Χαμηλή',
        'priority_normal' => 'Κανονική',
        'priority_high' => 'Υψηλή',
        'description' => 'Περιγραφή',
        'attachments' => 'Συνημμένα (προαιρετικά, έως :max αρχεία)',
        'send' => 'Αποστολή',
        'welcome' => 'Καλωσήρθες, :name. Δες τα αιτήματα υποστήριξης ή άνοιξε νέο.',
        'empty' => 'Δεν έχεις ανοίξει κάποιο αίτημα ακόμη.',
        'col_reference' => 'Κωδικός',
        'col_last_reply' => 'Τελευταία απάντηση',
        'show_title' => 'Αίτημα :reference',
        'you' => 'Εσείς',
        'support' => 'Υποστήριξη',
        'feedback_heading' => 'Πώς σας φάνηκε η εξυπηρέτηση;',
        'rating_current' => 'Η αξιολόγησή σας: :rating/5 — μπορείτε να την αλλάξετε.',
        'comment_optional' => 'Σχόλιο (προαιρετικά)',
        'submit_rating' => 'Υποβολή αξιολόγησης',
        'closed_note' => 'Το αίτημα είναι κλειστό — μια νέα απάντηση θα το ανοίξει ξανά.',
        'your_reply' => 'Η απάντησή σας',
        'send_reply' => 'Αποστολή απάντησης',
    ],

];
