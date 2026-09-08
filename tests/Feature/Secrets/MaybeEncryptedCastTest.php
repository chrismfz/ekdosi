<?php

namespace Tests\Feature\Secrets;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `MaybeEncrypted` cast: plaintext at rest by default (DR — mysqldump
 * self-sufficient, no APP_KEY needed), opt-in encryption, and ALWAYS decrypts
 * legacy ciphertext on read so flipping the flag never breaks existing rows.
 */
class MaybeEncryptedCastTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'Sec OE', 'slug' => 'sec-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'gsis_password' => 'gsis-pw',
            'einvoice_provider_config' => ['token' => 'T-123', 'url' => 'https://x'],
        ]);
    }

    private function raw(Company $c, string $col): ?string
    {
        return DB::table('companies')->where('id', $c->id)->value($col);
    }

    #[Test]
    public function plaintext_by_default_stores_clear_and_reads_back(): void
    {
        config(['ekdosi.secrets.encrypt_at_rest' => false]);
        $c = $this->company();

        // Stored in the clear → a mysqldump is self-sufficient.
        $this->assertSame('gsis-pw', $this->raw($c, 'gsis_password'));
        $this->assertSame('{"token":"T-123","url":"https://x"}', $this->raw($c, 'einvoice_provider_config'));

        // Reads back identically.
        $this->assertSame('gsis-pw', $c->fresh()->gsis_password);
        $this->assertSame(['token' => 'T-123', 'url' => 'https://x'], $c->fresh()->einvoice_provider_config);
    }

    #[Test]
    public function encrypt_flag_stores_ciphertext_but_reads_plaintext(): void
    {
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $c = $this->company();

        $raw = $this->raw($c, 'gsis_password');
        $this->assertNotSame('gsis-pw', $raw);
        $this->assertSame('gsis-pw', Crypt::decryptString($raw));      // genuinely encrypted
        $this->assertSame('gsis-pw', $c->fresh()->gsis_password);      // transparent on read
    }

    #[Test]
    public function read_decrypts_legacy_ciphertext_even_when_flag_is_off(): void
    {
        // Write encrypted (flag on)…
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $c = $this->company();
        $this->assertNotSame('gsis-pw', $this->raw($c, 'gsis_password'));

        // …then flip to plaintext mode. Existing ciphertext rows still read fine.
        config(['ekdosi.secrets.encrypt_at_rest' => false]);
        $this->assertSame('gsis-pw', $c->fresh()->gsis_password);
        $this->assertSame(['token' => 'T-123', 'url' => 'https://x'], $c->fresh()->einvoice_provider_config);
    }
}
