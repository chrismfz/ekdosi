<?php

namespace Tests\Feature\Secrets;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `secrets:reencrypt` rewrites the stored representation both ways and is the
 * tool you run after flipping EKDOSI_ENCRYPT_SECRETS_AT_REST.
 */
class SecretsReencryptCommandTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        config(['ekdosi.secrets.encrypt_at_rest' => false]);

        return Company::create([
            'name' => 'Sec OE', 'slug' => 'sec-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'gsis_password' => 'gsis-pw',
            'einvoice_provider_config' => ['token' => 'T-123'],
        ]);
    }

    private function raw(Company $c, string $col): ?string
    {
        return DB::table('companies')->where('id', $c->id)->value($col);
    }

    #[Test]
    public function plain_to_encrypted_and_back(): void
    {
        $this->withoutMockingConsoleOutput();
        $c = $this->company();
        $this->assertSame('gsis-pw', $this->raw($c, 'gsis_password')); // starts plaintext

        // → encrypted
        $this->artisan('secrets:reencrypt', ['--to' => 'encrypted']);
        $enc = $this->raw($c, 'gsis_password');
        $this->assertNotSame('gsis-pw', $enc);
        $this->assertSame('gsis-pw', Crypt::decryptString($enc));
        $this->assertSame('gsis-pw', $c->fresh()->gsis_password); // model still reads it

        // → back to plaintext
        $this->artisan('secrets:reencrypt', ['--to' => 'plain']);
        $this->assertSame('gsis-pw', $this->raw($c, 'gsis_password'));
        $this->assertSame('{"token":"T-123"}', $this->raw($c, 'einvoice_provider_config'));
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->withoutMockingConsoleOutput();
        $c = $this->company();

        $this->artisan('secrets:reencrypt', ['--to' => 'encrypted', '--dry-run' => true]);

        // Unchanged — still plaintext.
        $this->assertSame('gsis-pw', $this->raw($c, 'gsis_password'));
    }

    #[Test]
    public function rejects_bad_target(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->assertSame(1, $this->artisan('secrets:reencrypt', ['--to' => 'nonsense']));
    }

    #[Test]
    public function to_plain_skips_ciphertext_it_cannot_decrypt_and_fails_loudly(): void
    {
        $this->withoutMockingConsoleOutput();
        $c = $this->company();

        // Simulate a row encrypted under a DIFFERENT/lost APP_KEY: a value that
        // looks like a Laravel ciphertext blob but won't decrypt here.
        $garbageCipher = base64_encode(json_encode(['iv' => 'AAAA', 'value' => 'BBBB', 'mac' => 'CCCC']));
        DB::table('companies')->where('id', $c->id)->update(['gsis_password' => $garbageCipher]);

        // --to=plain must NOT write the unreadable blob as "plaintext" (data loss);
        // it skips it and exits non-zero so a deploy script notices.
        $exit = $this->artisan('secrets:reencrypt', ['--to' => 'plain']);
        $this->assertSame(1, $exit);

        // The blob is left untouched (not frozen as plaintext).
        $this->assertSame($garbageCipher, $this->raw($c, 'gsis_password'));
    }
}
