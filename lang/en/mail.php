<?php

declare(strict_types=1);

/**
 * Customer-email view strings (English). Twin of `lang/el/mail.php` — keep the
 * keys in sync. Rendered by `resources/views/mail/*` based on app()->getLocale()
 * (set per recipient by the mail caller).
 */
return [

    // Shared words/phrases reused across several mail views.
    'common' => [
        'regards' => 'Kind regards,',
    ],

    // invoice/issued.blade.php + issued_text.blade.php — contact-line chrome only
    // (the invoice body itself is rendered elsewhere and left untouched).
    'invoice' => [
        'contact' => [
            'phone' => 'Phone',
            'email' => 'Email',
            'afm' => 'VAT No.',
            'gemi' => 'Reg. No.',
        ],
    ],

    // quote/offer.blade.php
    'quote' => [
        // Bare word used in the email SUBJECT (envelope), e.g. «… — Quotation APY42».
        'subject_label' => 'Quotation',
        'heading' => 'Quotation :code',
        'greeting' => 'Dear customer,',
        'intro' => 'Please find our quotation attached.',
        'intro_with' => 'Please find attached our quotation for: :subject.',
        'valid_until' => 'Valid until:',
        'total' => 'Total:',
        'closing' => 'We remain at your disposal for any clarification.',
        'not_tax_doc' => 'This quotation is not a tax document.',
    ],

    // customer/statement.blade.php
    'statement' => [
        'heading' => 'Account statement',
        'greeting' => 'Dear :name,',
        'default_body' => 'Your account statement is attached as a PDF.',
    ],

    // ticket/reply.blade.php + reply_text.blade.php,
    // ticket/feedback.blade.php + feedback_text.blade.php
    'ticket' => [
        'reply' => [
            'footer' => 'Reply to support request :reference. Reply to this email to continue the conversation.',
        ],
        'feedback' => [
            'closed' => 'Your request :reference — ":subject" — has been closed.',
            'ask' => 'We would appreciate a short rating of our service:',
            // Shorter variant for the plain-text part (no button follows it there).
            'ask_text' => 'We would appreciate a short rating:',
            'button' => 'Rate the service',
            'fallback' => 'If the button does not work, copy the link:',
            'title' => 'How was our service?',
            'thanks' => 'Thank you,',
        ],
    ],

];
