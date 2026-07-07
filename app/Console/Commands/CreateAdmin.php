<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * `ekdosi:create-admin` — create (or password-reset) a SYSTEM super_admin without
 * running the full first-run installer. Complements `ekdosi:install` (which
 * bootstraps the FIRST admin + company): use this to add another super_admin, or
 * to recover access when no one can log in.
 *
 *   php artisan ekdosi:create-admin                       # interactive prompts
 *   php artisan ekdosi:create-admin --email=me@co.gr --password=… --name="Νίκος"
 *   php artisan ekdosi:create-admin --email=me@co.gr --password=… --reset   # reset an existing user
 *
 * super_admin is GLOBAL under Shield teams mode (holding it in ANY tenant
 * bypasses every policy everywhere), but a user must be a MEMBER of a tenant to
 * switch into it. So this attaches the user to every company and force-assigns
 * super_admin in each — reusing `shield:sync-super-admin --user`. Requires at
 * least one company to exist (run `ekdosi:install` first on a truly empty DB).
 */
class CreateAdmin extends Command
{
    protected $signature = 'ekdosi:create-admin
        {--name= : Display name (else prompted, default from the email)}
        {--email= : Login email (else prompted)}
        {--password= : Password (else prompted, hidden). Required to CREATE or --reset}
        {--reset : If the user already exists, RESET their password to the given one}';

    protected $description = 'Create (or password-reset) a system super_admin and assign it across all companies.';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive() && ! $this->option('no-interaction');

        $email = (string) ($this->option('email') ?: ($interactive ? $this->ask('Email διαχειριστή (login)') : ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error($email === '' ? 'Δώσε --email.' : "Μη έγκυρο email: «{$email}».");

            return self::INVALID;
        }

        if (Company::query()->doesntExist()) {
            $this->error('Δεν υπάρχει καμία εταιρία ακόμη — τρέξε πρώτα `php artisan ekdosi:install` '.
                '(δημιουργεί την πρώτη εταιρία + διαχειριστή).');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();
        $reset = (bool) $this->option('reset');

        // A password is needed to CREATE a new user, or to --reset an existing one.
        $needPassword = $existing === null || $reset;
        $password = (string) $this->option('password');
        if ($needPassword && $password === '') {
            if (! $interactive) {
                $this->error('Δώσε --password (για δημιουργία ή --reset).');

                return self::INVALID;
            }
            $password = (string) $this->secret('Κωδικός');
            if ($password === '' || $password !== (string) $this->secret('Επιβεβαίωση κωδικού')) {
                $this->error('Οι κωδικοί δεν ταιριάζουν (ή είναι κενοί).');

                return self::INVALID;
            }
        }

        $name = (string) ($this->option('name') ?: ($existing?->name ?: strstr($email, '@', true) ?: 'Admin'));

        $user = DB::transaction(function () use ($existing, $email, $name, $password, $reset): User {
            if ($existing === null) {
                return User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
            }

            if ($reset) {
                $existing->forceFill(['password' => Hash::make($password)])->save();
            }

            return $existing;
        });

        if ($existing !== null && ! $reset) {
            $this->warn("Ο χρήστης {$email} υπήρχε ήδη — ο κωδικός ΔΕΝ άλλαξε (πρόσθεσε --reset για επαναφορά).");
        } elseif ($reset && $existing !== null) {
            $this->info("Ο κωδικός του {$email} επαναφέρθηκε.");
        }

        // Member of every tenant, then force-assign super_admin in each — reuses
        // the proven `shield:sync-super-admin --user` path (ensures the role +
        // assigns it per company the user belongs to).
        $user->companies()->syncWithoutDetaching(Company::query()->pluck('id')->all());

        $code = Artisan::call('shield:sync-super-admin', [
            '--user' => $email,
            '--no-interaction' => true,
        ]);
        $this->line(trim(Artisan::output()));
        if ($code !== self::SUCCESS) {
            $this->error('Η ανάθεση super_admin απέτυχε — δες την έξοδο του shield:sync-super-admin παραπάνω.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✓ Ο {$email} είναι πλέον super_admin σε όλες τις εταιρίες.");
        $this->line('  Σύνδεση: '.rtrim((string) config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }
}
