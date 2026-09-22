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

    // Payment reminders (App\Services\Reminders\ReminderMessage). {placeholders}
    // are filled by the renderer (NOT Laravel :params); a tenant may override each
    // stage's subject/body in «Ρυθμίσεις εταιρείας».
    'reminder' => [
        'kind' => [
            'invoice' => 'τιμολόγιο',
            'proforma' => 'προτιμολόγιο',
        ],
        'pay_section' => 'Μπορείτε να εξοφλήσετε online εδώ: {pay_url}',
        'pre_due' => [
            'subject' => '{tenant_name} — Υπενθύμιση: το {document_kind} {invoice_code} λήγει στις {due_date}',
            'body' => "Αγαπητέ/ή {customer_name},\n\nσας υπενθυμίζουμε ότι το {document_kind} {invoice_code} (υπόλοιπο {balance}) λήγει στις {due_date}.\n\n{pay_section}\n\nΑν έχετε ήδη πληρώσει, αγνοήστε αυτό το μήνυμα.\n\nΜε εκτίμηση,\n{tenant_name}",
        ],
        'first' => [
            'subject' => '{tenant_name} — Υπενθύμιση πληρωμής: {document_kind} {invoice_code}',
            'body' => "Αγαπητέ/ή {customer_name},\n\nτο {document_kind} {invoice_code} έληξε στις {due_date} και παραμένει ανεξόφλητο (υπόλοιπο {balance}).\n\n{pay_section}\n\nΑν έχετε ήδη πληρώσει, αγνοήστε αυτό το μήνυμα.\n\nΜε εκτίμηση,\n{tenant_name}",
        ],
        'second' => [
            'subject' => '{tenant_name} — 2η υπενθύμιση πληρωμής: {document_kind} {invoice_code}',
            'body' => "Αγαπητέ/ή {customer_name},\n\nτο {document_kind} {invoice_code} είναι ληξιπρόθεσμο εδώ και {days_overdue} ημέρες (λήξη {due_date}, υπόλοιπο {balance}). Παρακαλούμε για την εξόφλησή του.\n\n{pay_section}\n\nΑν έχετε ήδη πληρώσει, αγνοήστε αυτό το μήνυμα.\n\nΜε εκτίμηση,\n{tenant_name}",
        ],
        'final' => [
            'subject' => '{tenant_name} — Τελευταία υπενθύμιση: {document_kind} {invoice_code}',
            'body' => "Αγαπητέ/ή {customer_name},\n\nπαρά τις προηγούμενες υπενθυμίσεις, το {document_kind} {invoice_code} παραμένει ανεξόφλητο εδώ και {days_overdue} ημέρες (λήξη {due_date}, υπόλοιπο {balance}). Παρακαλούμε να το τακτοποιήσετε άμεσα ή να επικοινωνήσετε μαζί μας.\n\n{pay_section}\n\nΜε εκτίμηση,\n{tenant_name}",
        ],
        'manual' => [
            'subject' => '{tenant_name} — Υπενθύμιση πληρωμής: {document_kind} {invoice_code}',
            'body' => "Αγαπητέ/ή {customer_name},\n\nσας υπενθυμίζουμε ότι το {document_kind} {invoice_code} (λήξη {due_date}) έχει ανεξόφλητο υπόλοιπο {balance}.\n\n{pay_section}\n\nΑν έχετε ήδη πληρώσει, αγνοήστε αυτό το μήνυμα.\n\nΜε εκτίμηση,\n{tenant_name}",
        ],
    ],

];
