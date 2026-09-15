@extends('install.layout')
@section('title', 'Εγκατάσταση')

@php
    // No session here (installer boots before APP_KEY), so repopulate straight
    // from the view data: old input wins, else the sensible default.
    $val = fn (string $k, string $d = '') => e($old[$k] ?? $defaults[$k] ?? $d);
    $sel = fn (string $k, string $option, string $d = '') => (($old[$k] ?? $defaults[$k] ?? $d) === $option) ? 'selected' : '';
@endphp

@section('content')
    @php
        $requirements = $requirements ?? [];
        $hasBlockers = $hasBlockers ?? false;
        $reqIcon = ['ok' => '✓', 'warn' => '⚠', 'error' => '✗'];
    @endphp

    @if (! empty($errors))
        <div class="alert alert-err">
            <strong>Διόρθωσε τα παρακάτω:</strong>
            <ul>
                @foreach ($errors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 0. Έλεγχος συστήματος (preflight) --}}
    @if (! empty($requirements))
        <div class="card">
            <h2>Έλεγχος συστήματος</h2>
            <p class="section-hint">Ο οδηγός ελέγχει το περιβάλλον <strong>χωρίς να αλλάζει τίποτα</strong>. Τα <strong>κόκκινα</strong> είναι υποχρεωτικά — διόρθωσέ τα στον διακομιστή και ανανέωσε τη σελίδα. Τα <strong>κίτρινα</strong> είναι προειδοποιήσεις: η εφαρμογή δουλεύει, αλλά η λειτουργία που αναφέρεται όχι.</p>

            @if ($hasBlockers)
                <div class="alert alert-err">❌ Το περιβάλλον δεν είναι έτοιμο. Διόρθωσε τα κρίσιμα σημεία παρακάτω και μετά <strong>ανανέωσε τη σελίδα</strong>. Το κουμπί «Εγκατάσταση» είναι απενεργοποιημένο μέχρι τότε.</div>
            @else
                <div class="alert alert-ok">✅ Όλες οι υποχρεωτικές απαιτήσεις καλύπτονται.</div>
            @endif

            <ul class="reqs">
                @foreach ($requirements as $r)
                    <li class="req-row req-{{ $r->severity() }}">
                        <span class="req-ico">{{ $reqIcon[$r->severity()] }}</span>
                        <span class="req-body">
                            <span class="req-label">{{ $r->label }}</span>
                            <span class="req-detail">{{ $r->detail }}</span>
                            @if (! $r->passed && $r->fix)
                                <span class="req-fix"><code>{{ $r->fix }}</code></span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/install') }}" id="install-form" enctype="multipart/form-data">
        {{-- Καμία CSRF: ο installer τρέχει χωρίς session· η ασφάλεια είναι ο κωδικός επιβεβαίωσης. --}}

        {{-- 1. Επιβεβαίωση πρόσβασης --}}
        <div class="card">
            <h2>1. Επιβεβαίωση πρόσβασης στον διακομιστή</h2>
            <p class="section-hint">Για ασφάλεια, ο οδηγός έγραψε ένα αρχείο στον διακομιστή. Άνοιξέ το (SSH ή File Manager του πάνελ), αντίγραψε τον κωδικό και επικόλλησέ τον εδώ.</p>

            @if ($tokenIssued)
                <p class="hint">Αρχείο: <span class="token-box">{{ $tokenPath }}</span></p>
            @else
                <div class="alert alert-warn">
                    Δεν μπόρεσα να δημιουργήσω το αρχείο επιβεβαίωσης — ο φάκελος <code>storage/app/install/</code> δεν είναι εγγράψιμος. Δώσε δικαιώματα εγγραφής και ανανέωσε τη σελίδα.
                </div>
            @endif

            <div class="field">
                <label for="verify_token">Κωδικός επιβεβαίωσης <span class="req">*</span></label>
                <input type="text" id="verify_token" name="verify_token" autocomplete="off" spellcheck="false" placeholder="π.χ. 3f9a…">
            </div>
        </div>

        {{-- 2. Εφαρμογή --}}
        <div class="card">
            <h2>2. Εφαρμογή</h2>
            <p class="section-hint">Βασικά στοιχεία. Το κλειδί κρυπτογράφησης (APP_KEY) δημιουργείται αυτόματα.</p>

            <div class="row">
                <div class="field">
                    <label for="app_name">Όνομα εφαρμογής <span class="req">*</span></label>
                    <input type="text" id="app_name" name="app_name" value="{{ $val('app_name') }}">
                </div>
                <div class="field">
                    <label for="app_url">Διεύθυνση (URL) <span class="req">*</span></label>
                    <input type="url" id="app_url" name="app_url" value="{{ $val('app_url') }}" placeholder="https://ekdosi.myip.gr">
                    <p class="hint">Η πραγματική διεύθυνση του site — χρησιμοποιείται σε PDF/email/QR/webhooks.</p>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="app_env">Περιβάλλον <span class="req">*</span></label>
                    <select id="app_env" name="app_env">
                        <option value="production" {{ $sel('app_env', 'production') }}>Production (παραγωγή)</option>
                        <option value="local" {{ $sel('app_env', 'local') }}>Local (δοκιμές)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="app_locale">Γλώσσα <span class="req">*</span></label>
                    <select id="app_locale" name="app_locale">
                        <option value="el" {{ $sel('app_locale', 'el') }}>Ελληνικά</option>
                        <option value="en" {{ $sel('app_locale', 'en') }}>English</option>
                    </select>
                </div>
                <div class="field">
                    <label for="app_timezone">Ζώνη ώρας <span class="req">*</span></label>
                    <input type="text" id="app_timezone" name="app_timezone" value="{{ $val('app_timezone') }}">
                </div>
            </div>
        </div>

        {{-- 3. Βάση δεδομένων --}}
        <div class="card">
            <h2>3. Βάση δεδομένων (MariaDB / MySQL)</h2>
            <p class="section-hint">Δημιούργησε πρώτα μια <strong>κενή</strong> βάση + χρήστη στο πάνελ του hosting, μετά συμπλήρωσε τα στοιχεία. Πάτα «Δοκιμή σύνδεσης» πριν συνεχίσεις.</p>

            <div class="row">
                <div class="field" style="flex: 2;">
                    <label for="db_host">Host <span class="req">*</span></label>
                    <input type="text" id="db_host" name="db_host" value="{{ $val('db_host') }}">
                </div>
                <div class="field">
                    <label for="db_port">Port <span class="req">*</span></label>
                    <input type="number" id="db_port" name="db_port" value="{{ $val('db_port') }}">
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="db_database">Όνομα βάσης <span class="req">*</span></label>
                    <input type="text" id="db_database" name="db_database" value="{{ $val('db_database') }}">
                </div>
                <div class="field">
                    <label for="db_username">Χρήστης <span class="req">*</span></label>
                    <input type="text" id="db_username" name="db_username" value="{{ $val('db_username') }}">
                </div>
                <div class="field">
                    <label for="db_password">Κωδικός</label>
                    <input type="password" id="db_password" name="db_password" autocomplete="new-password">
                </div>
            </div>

            <button type="button" class="btn-secondary" id="test-db-btn">Δοκιμή σύνδεσης</button>
            <div id="db-test-result" class="alert hidden" style="margin-top: 12px;"></div>

            <div class="field" style="margin-top: 14px;">
                <label style="font-weight: 400; display: flex; gap: 8px; align-items: flex-start;">
                    <input type="checkbox" name="allow_existing_db" value="1" style="width: auto; margin-top: 3px;" {{ ! empty($old['allow_existing_db']) ? 'checked' : '' }}>
                    <span>Συνέχεια σε μη-κενή βάση (μόνο για ημιτελή προηγούμενη προσπάθεια). Κανονικά η βάση πρέπει να είναι <strong>κενή</strong>.</span>
                </label>
            </div>
        </div>

        {{-- 4. Email (προαιρετικό) --}}
        <div class="card">
            <h2>4. Email</h2>
            <p class="section-hint">Με «Καταγραφή (log)» δεν στέλνεται τίποτα — τα email γράφονται στο log. Επίλεξε SMTP για πραγματική αποστολή (μπορείς να το ρυθμίσεις κι αργότερα).</p>

            <div class="row">
                <div class="field">
                    <label for="mail_mailer">Τρόπος αποστολής <span class="req">*</span></label>
                    <select id="mail_mailer" name="mail_mailer">
                        <option value="log" {{ $sel('mail_mailer', 'log') }}>Καταγραφή (log) — δεν στέλνει</option>
                        <option value="smtp" {{ $sel('mail_mailer', 'smtp') }}>SMTP</option>
                        <option value="sendmail" {{ $sel('mail_mailer', 'sendmail') }}>sendmail</option>
                    </select>
                </div>
                <div class="field">
                    <label for="mail_from_address">Αποστολέας (email) <span class="req">*</span></label>
                    <input type="email" id="mail_from_address" name="mail_from_address" value="{{ $val('mail_from_address') }}">
                </div>
                <div class="field">
                    <label for="mail_from_name">Αποστολέας (όνομα) <span class="req">*</span></label>
                    <input type="text" id="mail_from_name" name="mail_from_name" value="{{ $old['mail_from_name'] ?? $old['app_name'] ?? $defaults['app_name'] ?? 'ekdosi' }}">
                </div>
            </div>

            <div id="smtp-fields" class="{{ (($old['mail_mailer'] ?? $defaults['mail_mailer']) === 'smtp') ? '' : 'hidden' }}">
                <div class="row">
                    <div class="field" style="flex: 2;">
                        <label for="mail_host">SMTP host</label>
                        <input type="text" id="mail_host" name="mail_host" value="{{ $val('mail_host') }}">
                    </div>
                    <div class="field">
                        <label for="mail_port">SMTP port</label>
                        <input type="number" id="mail_port" name="mail_port" value="{{ $old['mail_port'] ?? '587' }}">
                    </div>
                    <div class="field">
                        <label for="mail_encryption">Κρυπτογράφηση</label>
                        <select id="mail_encryption" name="mail_encryption">
                            <option value="null" {{ $sel('mail_encryption', 'null') }}>Καμία</option>
                            <option value="tls" {{ $sel('mail_encryption', 'tls') }}>TLS</option>
                            <option value="ssl" {{ $sel('mail_encryption', 'ssl') }}>SSL</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="field">
                        <label for="mail_username">SMTP χρήστης</label>
                        <input type="text" id="mail_username" name="mail_username" value="{{ $val('mail_username') }}" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="mail_password">SMTP κωδικός</label>
                        <input type="password" id="mail_password" name="mail_password" autocomplete="new-password">
                    </div>
                </div>
                <div class="row">
                    <div class="field" style="flex: 2;">
                        <label for="test_recipient">Στείλε δοκιμαστικό σε (προαιρετικό)</label>
                        {{-- No `name`: test-only, NOT submitted with the install form. --}}
                        <input type="email" id="test_recipient" placeholder="π.χ. εσύ@example.gr" autocomplete="off">
                    </div>
                </div>
                <button type="button" class="btn-secondary" id="test-mail-btn">Δοκιμή email</button>
                <div id="mail-test-result" class="alert hidden" style="margin-top: 12px;"></div>
                <p class="hint" style="margin-top: 8px;">Ελέγχει host/port/κρυπτογράφηση/credentials με σύνδεση SMTP. Άφησε κενή τη διεύθυνση δοκιμής για έλεγχο χωρίς αποστολή· συμπλήρωσέ την για πραγματικό δοκιμαστικό email.</p>
            </div>
            {{-- Το select mail_encryption υποβάλλεται πάντα (ακόμη κι όταν είναι κρυμμένο),
                 με έγκυρη τιμή null/tls/ssl — καλύπτει τον κανόνα επικύρωσης χωρίς διπλό πεδίο. --}}
        </div>

        {{-- 5. Διαχειριστής & Εταιρία --}}
        <div class="card">
            <h2>5. Διαχειριστής & πρώτη εταιρία</h2>
            <p class="section-hint">Ο πρώτος υπερ-διαχειριστής (super admin) και η πρώτη εταιρία (tenant). Τα κλειδιά myDATA/WHMCS/GSIS ρυθμίζονται αργότερα, ανά εταιρία, μέσα από την εφαρμογή.</p>

            <div class="row">
                <div class="field">
                    <label for="admin_name">Όνομα διαχειριστή <span class="req">*</span></label>
                    <input type="text" id="admin_name" name="admin_name" value="{{ $val('admin_name') }}">
                </div>
                <div class="field">
                    <label for="admin_email">Email (login) <span class="req">*</span></label>
                    <input type="email" id="admin_email" name="admin_email" value="{{ $val('admin_email') }}" autocomplete="off">
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="admin_password">Κωδικός (≥ 8 χαρακτ.) <span class="req">*</span></label>
                    <input type="password" id="admin_password" name="admin_password" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="admin_password_confirmation">Επιβεβαίωση κωδικού <span class="req">*</span></label>
                    <input type="password" id="admin_password_confirmation" name="admin_password_confirmation" autocomplete="new-password">
                </div>
            </div>

            @php $mode = $old['install_mode'] ?? $defaults['install_mode'] ?? 'new'; @endphp
            <div class="field" style="margin-top: 6px;">
                <label>Πρώτη εταιρία <span class="req">*</span></label>
                <div class="mode-toggle">
                    <label class="mode-opt">
                        <input type="radio" name="install_mode" value="new" {{ $mode === 'new' ? 'checked' : '' }}>
                        <span><strong>Νέα εταιρία</strong> — συμπλήρωσε τα στοιχεία παρακάτω</span>
                    </label>
                    <label class="mode-opt">
                        <input type="radio" name="install_mode" value="import" {{ $mode === 'import' ? 'checked' : '' }}>
                        <span><strong>Εισαγωγή από αρχείο (.zip)</strong> — ανέβασε ένα backup εταιρίας (<code>company:export</code>)· τα στοιχεία, οι ρυθμίσεις, τα κλειδιά και οι χειριστές έρχονται από αυτό</span>
                    </label>
                </div>
            </div>

            {{-- Νέα εταιρία --}}
            <div id="company-fields" class="{{ $mode === 'import' ? 'hidden' : '' }}">
                <div class="row">
                    <div class="field">
                        <label for="company_name">Επωνυμία εταιρίας <span class="req">*</span></label>
                        <input type="text" id="company_name" name="company_name" value="{{ $val('company_name') }}">
                    </div>
                    <div class="field">
                        <label for="company_slug">Slug (προαιρετικό)</label>
                        <input type="text" id="company_slug" name="company_slug" value="{{ $val('company_slug') }}" placeholder="αυτόματο από την επωνυμία">
                    </div>
                </div>
                <div class="row">
                    <div class="field">
                        <label for="company_country">Χώρα <span class="req">*</span></label>
                        <select id="company_country" name="company_country">
                            <option value="GR" {{ $sel('company_country', 'GR') }}>Ελλάδα (myDATA)</option>
                            <option value="EE" {{ $sel('company_country', 'EE') }}>Εσθονία (PEPPOL)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="company_afm">ΑΦΜ (προαιρετικό)</label>
                        <input type="text" id="company_afm" name="company_afm" value="{{ $val('company_afm') }}">
                    </div>
                </div>
            </div>

            {{-- Εισαγωγή από .zip --}}
            <div id="import-fields" class="{{ $mode === 'import' ? '' : 'hidden' }}">
                <div class="field">
                    <label for="bundle">Αρχείο εταιρίας (.zip) <span class="req">*</span></label>
                    <input type="file" id="bundle" name="bundle" accept=".zip,application/zip">
                    <p class="hint">Το .zip που έβγαλε το <code>php artisan company:export</code> στην άλλη εγκατάσταση.</p>
                </div>
                <div class="field">
                    <label for="bundle_passphrase">Συνθηματικό αρχείου</label>
                    <input type="password" id="bundle_passphrase" name="bundle_passphrase" autocomplete="new-password" placeholder="άφησέ το κενό αν το .zip είναι χωρίς κρυπτογράφηση">
                    <p class="hint">Χρειάζεται <strong>μόνο</strong> αν το export έγινε με συνθηματικό. Για μη-κρυπτογραφημένο (raw) .zip, άφησέ το κενό.</p>
                </div>
            </div>
        </div>

        <div class="card" style="text-align: center;">
            <button type="submit" class="btn-primary" id="submit-btn" {{ $hasBlockers ? 'disabled' : '' }}>Εγκατάσταση</button>
            @if ($hasBlockers)
                <p class="hint" style="margin-top: 10px;">Απενεργοποιημένο: κάλυψε πρώτα τις υποχρεωτικές απαιτήσεις στην ενότητα «Έλεγχος συστήματος».</p>
            @else
                <p class="hint" style="margin-top: 10px;">Θα δημιουργηθεί το σχήμα της βάσης, ο διαχειριστής και το αρχείο ρυθμίσεων. Μπορεί να πάρει λίγα δευτερόλεπτα.</p>
            @endif
        </div>
    </form>
@endsection

@section('scripts')
<script>
    (function () {
        // SMTP fields toggle. The mail_encryption select stays in the DOM and
        // submits a valid value even while hidden, so no extra field is needed.
        var mailer = document.getElementById('mail_mailer');
        var smtp = document.getElementById('smtp-fields');
        function toggleSmtp() {
            smtp.classList.toggle('hidden', mailer.value !== 'smtp');
        }
        mailer.addEventListener('change', toggleSmtp);
        toggleSmtp();

        // Mode toggle: «Νέα εταιρία» vs «Εισαγωγή από .zip».
        var modeRadios = document.querySelectorAll('input[name="install_mode"]');
        var companyFields = document.getElementById('company-fields');
        var importFields = document.getElementById('import-fields');
        function toggleMode() {
            var checked = document.querySelector('input[name="install_mode"]:checked');
            var isImport = checked && checked.value === 'import';
            companyFields.classList.toggle('hidden', isImport);
            importFields.classList.toggle('hidden', !isImport);
        }
        Array.prototype.forEach.call(modeRadios, function (r) { r.addEventListener('change', toggleMode); });
        toggleMode();

        // Δοκιμή σύνδεσης (AJAX).
        var btn = document.getElementById('test-db-btn');
        var box = document.getElementById('db-test-result');
        btn.addEventListener('click', function () {
            var payload = {
                verify_token: document.getElementById('verify_token').value,
                db_host: document.getElementById('db_host').value,
                db_port: document.getElementById('db_port').value,
                db_database: document.getElementById('db_database').value,
                db_username: document.getElementById('db_username').value,
                db_password: document.getElementById('db_password').value
            };
            btn.disabled = true;
            btn.textContent = 'Έλεγχος…';
            box.className = 'alert';
            box.classList.remove('hidden');
            box.textContent = 'Δοκιμή σύνδεσης…';

            fetch('{{ url('/install/test-db') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
            .then(function (res) {
                var d = res.body;
                if (d.ok) {
                    box.className = 'alert alert-ok';           // connected + empty
                } else if (d.needsOverride) {
                    box.className = 'alert alert-warn';         // connected but non-empty
                } else {
                    // connection failed, OR a hard stop we connected fine for
                    // (reason 'unmigratable'). Red is right for both — the
                    // message says which; there is no override to offer.
                    box.className = 'alert alert-err';
                }
                box.textContent = d.message || 'Άγνωστο αποτέλεσμα.';
            })
            .catch(function () {
                box.className = 'alert alert-err';
                box.textContent = 'Αποτυχία επικοινωνίας με τον διακομιστή.';
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Δοκιμή σύνδεσης';
            });
        });

        // Δοκιμή email (AJAX). Connect+auth, and a real send when a test
        // recipient is filled. Green on ok, red otherwise — the message says why.
        var mailBtn = document.getElementById('test-mail-btn');
        var mailBox = document.getElementById('mail-test-result');
        if (mailBtn) {
            mailBtn.addEventListener('click', function () {
                var payload = {
                    verify_token: document.getElementById('verify_token').value,
                    mail_host: document.getElementById('mail_host').value,
                    mail_port: document.getElementById('mail_port').value,
                    mail_encryption: document.getElementById('mail_encryption').value,
                    mail_username: document.getElementById('mail_username').value,
                    mail_password: document.getElementById('mail_password').value,
                    mail_from_address: document.getElementById('mail_from_address').value,
                    mail_from_name: document.getElementById('mail_from_name').value,
                    test_recipient: document.getElementById('test_recipient').value
                };
                mailBtn.disabled = true;
                mailBtn.textContent = 'Έλεγχος…';
                mailBox.className = 'alert';
                mailBox.classList.remove('hidden');
                mailBox.textContent = 'Δοκιμή email…';

                fetch('{{ url('/install/test-mail') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
                .then(function (res) {
                    var d = res.body;
                    mailBox.className = d.ok ? 'alert alert-ok' : 'alert alert-err';
                    mailBox.textContent = d.message || 'Άγνωστο αποτέλεσμα.';
                })
                .catch(function () {
                    mailBox.className = 'alert alert-err';
                    mailBox.textContent = 'Αποτυχία επικοινωνίας με τον διακομιστή.';
                })
                .finally(function () {
                    mailBtn.disabled = false;
                    mailBtn.textContent = 'Δοκιμή email';
                });
            });
        }

        // Απόφυγε διπλό submit (η εγκατάσταση αργεί λίγο).
        document.getElementById('install-form').addEventListener('submit', function () {
            var s = document.getElementById('submit-btn');
            s.disabled = true;
            s.textContent = 'Εγκατάσταση… περίμενε';
        });
    })();
</script>
@endsection
