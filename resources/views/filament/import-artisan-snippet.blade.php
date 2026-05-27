{{-- PR #30 followup — operator-facing snippet of the artisan command.
     Rendered inside the Firebird Import form so operators can run
     the command on the host directly when the upload exceeds PHP
     limits (or for scripting). The password placeholder is NEVER
     filled in by the server — operator types it in the shell.
     Company id comes from the active Filament tenant.
--}}
<div class="text-sm space-y-3">
    <div class="font-medium text-gray-700 dark:text-gray-300">
        Bash equivalent (run on the ekdosi host, in the project root):
    </div>

    <pre class="
        rounded
        bg-gray-900 dark:bg-gray-950
        text-gray-100
        p-3
        overflow-x-auto
        text-xs
        leading-relaxed
        font-mono
    "><code>php artisan migrate:firebird \
    --company-id={{ $companyId }} \
    --fdb=/absolute/path/to/your.fdb \
    --host=127.0.0.1 \
    --fbuser=SYSDBA \
    --fbpass=YOUR_PASSWORD</code></pre>

    <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
        <div>• <strong>--fdb=</strong> can be either a `.fdb` (used as-is) or a `.fbk` you've already restored on the host. The CLI doesn't run `gbak -r` itself.</div>
        <div>• <strong>--fbpass=</strong> is plaintext in the shell history — clear it (`history -d &lt;n&gt;`) on shared hosts.</div>
        <div>• The command is re-run-safe: existing ekdosi data + manual edits survive. See CLAUDE.md "Deferred from PR #29" for the upsert semantics.</div>
        <div>• Exit code 0 = imported successfully. Non-zero = something failed; check the printed lines.</div>
    </div>
</div>
