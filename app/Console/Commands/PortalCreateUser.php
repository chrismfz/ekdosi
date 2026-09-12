<?php

namespace App\Console\Commands;

use App\Models\CustomerUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Mint (or update) a customer-portal login by hand — the only way to create one
 * in Slice 0 (registration / operator-invite / backfill come later). Handy for
 * testing the login shell. The password is prompted hidden unless --password is
 * given; the login is created ACTIVE and ready to sign in.
 */
class PortalCreateUser extends Command
{
    protected $signature = 'portal:create-user
        {email : The login email (global identity)}
        {--name= : Display name (defaults to the email local-part)}
        {--password= : Password (omit to be prompted, hidden)}';

    protected $description = 'Create or update a customer-portal login (CustomerUser)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = (string) ($this->option('name') ?: Str::before($email, '@'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', Password::defaults()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Look up WITH trashed: the unique(email) index still holds a soft-deleted
        // row, so a plain updateOrCreate would try to INSERT and hit the constraint.
        // Restore + update the existing (possibly trashed) login instead.
        $user = CustomerUser::withTrashed()->where('email', $email)->first();
        $existing = $user !== null;
        if ($user === null) {
            $user = new CustomerUser(['email' => $email]);
        }

        // 'password' is a hashed cast → assigning the plaintext hashes it.
        $user->forceFill([
            'name' => $name,
            'password' => $password,
            'status' => CustomerUser::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'deleted_at' => null,   // un-trash if it was soft-deleted
        ])->save();

        $this->info(($existing ? 'Updated' : 'Created')." portal login #{$user->id} <{$user->email}> (active).");

        return self::SUCCESS;
    }
}
