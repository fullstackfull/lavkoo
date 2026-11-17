<?php

namespace Tests\Support;

use Laravel\Passport\Passport;

/**
 * Passport refuses to look at a token without a key pair, and the repository has none. A throwaway pair is made for the test and removed after
 * it, so that the customer API answers a token it does not know with 401, as it does in production, instead of failing on the missing key.
 */
trait LoadsPassportKeys
{
    private ?string $passportKeyDirectory = null;

    protected function loadPassportKeys(): void
    {
        $directory = sys_get_temp_dir() . '/platform-admin-test-keys-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $this->passportKeyDirectory = $directory;

        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);
        file_put_contents($directory . '/oauth-private.key', $private);
        file_put_contents($directory . '/oauth-public.key', openssl_pkey_get_details($pair)['key']);
        Passport::loadKeysFrom($directory);
    }

    protected function removePassportKeys(): void
    {
        if ($this->passportKeyDirectory === null) {
            return;
        }

        foreach ((array) glob($this->passportKeyDirectory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->passportKeyDirectory);
        $this->passportKeyDirectory = null;
    }
}
