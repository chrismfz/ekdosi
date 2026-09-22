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

    // Payment reminders (App\Services\Reminders\ReminderMessage). {placeholders}
    // are filled by the renderer (NOT Laravel :params).
    'reminder' => [
        'kind' => [
            'invoice' => 'invoice',
            'proforma' => 'proforma invoice',
        ],
        'pay_section' => 'You can pay online here: {pay_url}',
        'pre_due' => [
            'subject' => '{tenant_name} — Reminder: {document_kind} {invoice_code} is due on {due_date}',
            'body' => "Dear {customer_name},\n\nthis is a friendly reminder that {document_kind} {invoice_code} (balance {balance}) is due on {due_date}.\n\n{pay_section}\n\nIf you have already paid, please disregard this message.\n\nKind regards,\n{tenant_name}",
        ],
        'first' => [
            'subject' => '{tenant_name} — Payment reminder: {document_kind} {invoice_code}',
            'body' => "Dear {customer_name},\n\n{document_kind} {invoice_code} was due on {due_date} and is still unpaid (balance {balance}).\n\n{pay_section}\n\nIf you have already paid, please disregard this message.\n\nKind regards,\n{tenant_name}",
        ],
        'second' => [
            'subject' => '{tenant_name} — Second payment reminder: {document_kind} {invoice_code}',
            'body' => "Dear {customer_name},\n\n{document_kind} {invoice_code} is {days_overdue} days overdue (due {due_date}, balance {balance}). Please arrange payment.\n\n{pay_section}\n\nIf you have already paid, please disregard this message.\n\nKind regards,\n{tenant_name}",
        ],
        'final' => [
            'subject' => '{tenant_name} — Final reminder: {document_kind} {invoice_code}',
            'body' => "Dear {customer_name},\n\ndespite our previous reminders, {document_kind} {invoice_code} remains unpaid {days_overdue} days after its due date ({due_date}, balance {balance}). Please settle it promptly or contact us.\n\n{pay_section}\n\nKind regards,\n{tenant_name}",
        ],
        'manual' => [
            'subject' => '{tenant_name} — Payment reminder: {document_kind} {invoice_code}',
            'body' => "Dear {customer_name},\n\na reminder that {document_kind} {invoice_code} (due {due_date}) has an outstanding balance of {balance}.\n\n{pay_section}\n\nIf you have already paid, please disregard this message.\n\nKind regards,\n{tenant_name}",
        ],
    ],

];
