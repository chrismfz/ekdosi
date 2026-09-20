<?php

declare(strict_types=1);

/**
 * Customer portal UI strings (English). Twin of `lang/el/portal.php` — keep the
 * keys in sync. Rendered by `resources/views/portal/*` and the `portal-layout`
 * component based on app()->getLocale() (set per customer user by SetPortalLocale).
 */
return [

    // Flash/status banners set by portal controllers (authenticated actions).
    'flash' => [
        'profile_saved' => 'Your details have been saved.',
        'password_changed' => 'Your password has been changed.',
        'ticket_created' => 'Your request has been submitted — you will be notified of the reply.',
        'reply_sent' => 'Your reply has been sent.',
        'rating_thanks' => 'Thank you for your feedback!',
    ],

    // Shared words/phrases reused across several portal pages.
    'common' => [
        'my_documents' => 'My documents',
        'my_statement' => 'My statement',
        'my_requests' => 'My requests',
        'email' => 'Email',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'afm' => 'VAT No.',
        'via_me' => 'Via me',
        'date' => 'Date',
        'document' => 'Document',
        'type' => 'Type',
        'status' => 'Status',
        'mark' => 'MARK',
        'download' => 'Download',
        'on_mydata' => 'On myDATA',
        'issued' => 'Issued',
        'verify' => 'Verify',
        'pdf' => 'PDF',
        'balance' => 'Balance',
        'charge' => 'Charge',
        'credit' => 'Credit',
        'payment' => 'Payment',
        'owed_balance' => 'Outstanding balance',
        'new_password' => 'New password',
        'department' => 'Department',
        'subject' => 'Subject',
    ],

    // Layout: brand + top navigation + sign out.
    'nav' => [
        'brand' => 'Customer Portal',
        'details' => 'My details',
        'sign_out' => 'Sign out',
    ],

    // "My documents" — issued documents list.
    'home' => [
        'welcome' => 'Welcome, :name. Here you can see the documents issued to you.',
        'no_documents_yet' => 'No documents yet.',
        'recent_note' => 'Showing the :count most recent documents. For older ones, please contact us.',
        'empty' => 'There are no documents available for your account yet.',
    ],

    // "My statement" — balance + transactions.
    'statement' => [
        'welcome' => 'Welcome, :name. Here you can see your balance and transactions.',
        'oldest_unpaid' => 'Oldest unpaid: :days days',
        'credit_balance' => 'Credit balance (in your favour)',
        'no_movement' => 'No activity',
        'settled' => 'Settled',
        'pay' => 'Pay',
        'no_activity_yet' => 'No activity yet.',
        'movement' => 'Transaction',
        'badge_credit' => 'Credit note',
        'badge_refund' => 'Refund',
        'balance_note' => 'The balance covers credit-term documents; cash sales are settled on issue.',
        'see_my_documents' => 'View my documents',
        'empty' => 'There is no statement available for your account yet.',
    ],

    // "My details" — account + password.
    'profile' => [
        'title' => 'My details',
        'subtitle' => 'Manage your account and password.',
        'account_details' => 'Account details',
        'name' => 'Name',
        'email_hint' => 'Your email is your login identifier — it cannot be changed here.',
        'phone' => 'Phone',
        'language' => 'Language',
        // Endonyms: a language picker shows each option in its OWN language, so
        // the dropdown reads «Ελληνικά | English» regardless of the UI locale.
        'lang_el' => 'Ελληνικά',
        'lang_en' => 'English',
        'change_password' => 'Change password',
        'current_password' => 'Current password',
        'confirm_new_password' => 'Confirm new password',
    ],

    // Customer sign-in.
    'login' => [
        'title' => 'Customer sign-in',
        'subtitle' => 'View your documents and details.',
        'password' => 'Password',
        'remember' => 'Remember me',
        'forgot' => 'Forgot password',
        'submit' => 'Sign in',
        'failed' => 'Wrong email or password.',
    ],

    // Forgot / reset password.
    'password' => [
        'title' => 'Set password',
        'forgot_heading' => 'Forgot your password?',
        'forgot_intro' => 'Enter your email and we will send you a link to set your password. If you are a new user who was just invited, this is where you set your first password.',
        'honeypot' => 'Do not fill this in',
        'send_link' => 'Send link',
        'back_to_login' => 'Back to sign-in',
        'reset_heading' => 'Set a new password',
        'reset_intro' => 'Choose a password for your account.',
        'confirm_password' => 'Confirm password',
    ],

    // Payment: create / redirect / show.
    'payment' => [
        'credit_pick_one' => 'Pick at least one document.',
        'credit_nothing_applied' => 'Nothing was applied — the documents you picked have no open balance.',
        'use_credit_multi_hint' => 'Pick as many as you like — the credit is applied to each in turn until it runs out.',
        'available_credit' => 'Credit balance (in your favour)',
        'use_credit' => 'Use credit',
        'use_credit_hint' => 'You have :amount in credit. Pick a document to settle it without a new payment.',
        'use_credit_submit' => 'Settle from credit',
        'credit_applied' => ':amount applied to :document from your credit.',
        'proforma_badge' => 'Proforma',
        'proforma_note' => 'Proforma — not a tax document. The final document is issued once it is settled.',
        'no_method' => 'No payment method is available right now. Please contact us.',
        'what' => 'What are you paying?',
        'whole_balance' => 'Whole balance (:amount)',
        'amount_field' => 'Amount (€)',
        'method' => 'Payment method',
        'continue' => 'Continue',
        'back_to_statement' => 'Back to statement',
        'redirect_title' => 'Redirecting to payment',
        'redirect_intro' => 'We are securely transferring you to the bank\'s payment page for :amount. If you are not redirected automatically, press «Continue».',
        'continue_to_payment' => 'Continue to payment',
        'cancel_back' => 'Cancel — back to statement',
        'instructions_title' => 'Payment instructions',
        'settled' => 'Your payment has been recorded. Thank you!',
        'follow_instructions' => 'Follow the instructions to complete your payment. It will be recorded once confirmed.',
        'amount' => 'Amount',
        'reference' => 'Reference code',
        'details' => 'Payment details',
        'mention_reference' => 'In the payment reference, mention the code :reference.',
    ],

    // Support tickets: create / index / show.
    'tickets' => [
        'new' => 'New request',
        'new_heading' => 'New support request',
        'new_intro' => 'Describe your issue and we will get back to you as soon as possible.',
        'no_department' => 'No support department is available right now. Please try again later.',
        'priority' => 'Priority',
        'priority_low' => 'Low',
        'priority_normal' => 'Normal',
        'priority_high' => 'High',
        'description' => 'Description',
        'attachments' => 'Attachments (optional, up to :max files)',
        'send' => 'Send',
        'welcome' => 'Welcome, :name. View your support requests or open a new one.',
        'empty' => 'You have not opened any requests yet.',
        'col_reference' => 'Reference',
        'col_last_reply' => 'Last reply',
        'show_title' => 'Request :reference',
        'you' => 'You',
        'support' => 'Support',
        'feedback_heading' => 'How was our service?',
        'rating_current' => 'Your rating: :rating/5 — you can change it.',
        'comment_optional' => 'Comment (optional)',
        'submit_rating' => 'Submit rating',
        'closed_note' => 'This request is closed — a new reply will reopen it.',
        'your_reply' => 'Your reply',
        'send_reply' => 'Send reply',
    ],

];
