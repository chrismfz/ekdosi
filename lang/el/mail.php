<?php

declare(strict_types=1);

/**
 * Customer-email view strings (Greek). Rendered by `resources/views/mail/*`
 * based on app()->getLocale() (set per recipient by the mail caller). The `en`
 * twin lives beside this file — keep the keys in sync.
 *
 * Values here are byte-verbatim copies of the original hardcoded blade text.
 */
return [

    // Shared words/phrases reused across several mail views.
    'common' => [
        'regards' => 'Με εκτίμηση,',
    ],

    // invoice/issued.blade.php + issued_text.blade.php — contact-line chrome only
    // (the invoice body itself is rendered elsewhere and left untouched).
    'invoice' => [
        'contact' => [
            'phone' => 'Τηλέφωνο',
            'email' => 'Email',
            'afm' => 'ΑΦΜ',
            'gemi' => 'ΓΕΜΗ',
        ],
    ],

    // quote/offer.blade.php
    'quote' => [
        // Bare word used in the email SUBJECT (envelope), e.g. «… — Προσφορά APY42».
        'subject_label' => 'Προσφορά',
        'heading' => 'Προσφορά :code',
        'greeting' => 'Αξιότιμε/η πελάτη,',
        'intro' => 'Σας αποστέλλουμε συνημμένα την προσφορά μας.',
        'intro_with' => 'Σας αποστέλλουμε συνημμένα την προσφορά μας για: :subject.',
        'valid_until' => 'Ισχύει έως:',
        'total' => 'Συνολική αξία:',
        'closing' => 'Παραμένουμε στη διάθεσή σας για οποιαδήποτε διευκρίνιση.',
        'not_tax_doc' => 'Η παρούσα προσφορά δεν αποτελεί φορολογικό παραστατικό.',
    ],

    // customer/statement.blade.php
    'statement' => [
        'heading' => 'Καρτέλα πελάτη',
        'greeting' => 'Αγαπητέ/ή :name,',
        'default_body' => 'Επισυνάπτεται η καρτέλα κινήσεων του λογαριασμού σας σε μορφή PDF.',
    ],

    // ticket/reply.blade.php + reply_text.blade.php,
    // ticket/feedback.blade.php + feedback_text.blade.php
    'ticket' => [
        'reply' => [
            'footer' => 'Απάντηση στο αίτημα υποστήριξης :reference. Απαντήστε σε αυτό το email για να συνεχίσετε τη συνομιλία.',
        ],
        'feedback' => [
            'closed' => 'Το αίτημά σας :reference — «:subject» — έκλεισε.',
            'ask' => 'Θα εκτιμούσαμε πολύ μια σύντομη αξιολόγηση της εξυπηρέτησης:',
            // Shorter variant for the plain-text part (no button follows it there).
            'ask_text' => 'Θα εκτιμούσαμε πολύ μια σύντομη αξιολόγηση:',
            'button' => 'Αξιολόγηση εξυπηρέτησης',
            'fallback' => 'Αν το κουμπί δεν λειτουργεί, αντιγράψτε τον σύνδεσμο:',
            'title' => 'Πώς σας φάνηκε η εξυπηρέτηση;',
            'thanks' => 'Σας ευχαριστούμε,',
        ],
    ],

];
