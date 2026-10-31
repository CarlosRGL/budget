<?php

namespace Tests\Feature\Banking;

use App\Banking\EnableBankingClient;
use App\Banking\EnableBankingException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnableBankingClientTest extends TestCase
{
    use FakesEnableBanking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEnableBanking();
    }

    public function test_requests_are_signed_with_a_short_lived_rs256_jwt(): void
    {
        $token = app(EnableBankingClient::class)->token();
        [$header, $claims, $signature] = explode('.', $token);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], json_decode($decode($header), true));

        $claims = json_decode($decode($claims), true);
        $this->assertSame('enablebanking.com', $claims['iss']);
        $this->assertSame('api.enablebanking.com', $claims['aud']);
        $this->assertLessThanOrEqual(86400, $claims['exp'] - $claims['iat']);
        $this->assertSame(1, openssl_verify("{$header}.".explode('.', $token)[1], $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_the_key_can_come_from_the_environment(): void
    {
        config([
            'services.enablebanking.private_key_path' => '/nonexistent/key.pem',
            'services.enablebanking.private_key' => base64_encode($this->privateKeyPem),
        ]);
        $client = app(EnableBankingClient::class);

        $this->assertTrue($client->isConfigured());
        [$header, $claims, $signature] = explode('.', $client->token());
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_it_is_not_configured_without_a_key(): void
    {
        config(['services.enablebanking.private_key_path' => '/nonexistent/key.pem']);
        $client = app(EnableBankingClient::class);

        $this->assertFalse($client->isConfigured());
        $this->expectException(EnableBankingException::class);
        $client->token();
    }

    public function test_banks_of_a_country(): void
    {
        Http::fake(['api.enablebanking.com/aspsps*' => Http::response($this->fixture('aspsps'))]);

        $aspsps = app(EnableBankingClient::class)->aspsps('FR');

        $this->assertSame(['Crédit Agricole', 'Boursorama'], array_column($aspsps, 'name'));
        Http::assertSent(fn (Request $request) => $request['country'] === 'FR'
            && str_starts_with($request->header('Authorization')[0], 'Bearer '));
    }

    public function test_starting_an_authorization(): void
    {
        Http::fake(['api.enablebanking.com/auth' => Http::response($this->fixture('auth'))]);

        $result = app(EnableBankingClient::class)->startAuthorization('Crédit Agricole', 'FR', 'state-123', 'https://budget.test/bank/callback', CarbonImmutable::parse('2027-04-28 10:00:00', 'UTC'));

        $this->assertStringStartsWith('https://tilisy.enablebanking.com/', $result['url']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['aspsp'] === ['name' => 'Crédit Agricole', 'country' => 'FR']
            && $request['state'] === 'state-123'
            && $request['redirect_url'] === 'https://budget.test/bank/callback'
            && $request['psu_type'] === 'personal'
            && $request['access']['valid_until'] === '2027-04-28T10:00:00+00:00');
    }

    public function test_transactions_follow_the_continuation_key(): void
    {
        Http::fake(['api.enablebanking.com/accounts/*/transactions*' => function (Request $request) {
            return Http::response($this->fixture(isset($request['continuation_key']) ? 'transactions-page-2' : 'transactions-page-1'));
        }]);

        $transactions = app(EnableBankingClient::class)->transactions('07cc67f4', CarbonImmutable::parse('2026-10-01'), ['Psu-Ip-Address' => '10.0.0.1']);

        $this->assertCount(3, $transactions);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['date_from'] === '2026-10-01' && $request->hasHeader('Psu-Ip-Address', '10.0.0.1'));
        Http::assertSent(fn (Request $request) => ($request['continuation_key'] ?? null) === 'c2VjcmV0LXBhZ2UtMg==');
    }

    public function test_api_errors_carry_the_enablebanking_code(): void
    {
        Http::fake(['api.enablebanking.com/*' => Http::response($this->fixture('error-expired-session'), 401)]);

        try {
            app(EnableBankingClient::class)->balances('07cc67f4');
            $this->fail('No exception thrown.');
        } catch (EnableBankingException $exception) {
            $this->assertSame('EXPIRED_SESSION', $exception->errorCode);
            $this->assertSame('Session is expired', $exception->getMessage());
            $this->assertTrue($exception->isExpiredConsent());
        }
    }
}
