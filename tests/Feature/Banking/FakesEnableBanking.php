<?php

namespace Tests\Feature\Banking;

/**
 * EnableBanking configured with a throwaway key pair, and its recorded answers.
 */
trait FakesEnableBanking
{
    protected string $publicKey;

    protected string $privateKeyPem;

    protected function configureEnableBanking(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->privateKeyPem = $privateKey;

        $path = tempnam(sys_get_temp_dir(), 'enablebanking');
        file_put_contents($path, $privateKey);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        config([
            'services.enablebanking.app_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'services.enablebanking.private_key' => null,
            'services.enablebanking.private_key_path' => $path,
            'services.enablebanking.base_url' => 'https://api.enablebanking.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/enablebanking/{$name}.json")), true);
    }
}
