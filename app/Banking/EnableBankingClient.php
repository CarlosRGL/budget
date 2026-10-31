<?php

namespace App\Banking;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * EnableBanking API (https://enablebanking.com/docs/api/reference/).
 *
 * Every call is signed with a short-lived RS256 JWT whose `kid` is the
 * application id and whose key is the application's private key.
 */
class EnableBankingClient
{
    /**
     * Lifetime of the signed JWT, in seconds (EnableBanking accepts up to 24 hours).
     */
    public const TOKEN_LIFETIME = 3600;

    public function __construct(
        private ?string $appId,
        private string $privateKeyPath,
        private string $baseUrl,
        private ?string $privateKey = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.enablebanking.app_id'),
            (string) config('services.enablebanking.private_key_path'),
            (string) config('services.enablebanking.base_url'),
            config('services.enablebanking.private_key'),
        );
    }

    /**
     * Determine if the application id and private key are available.
     */
    public function isConfigured(): bool
    {
        return filled($this->appId) && $this->privateKey() !== null;
    }

    /**
     * What is missing for the setup guide, as booleans only: the key itself
     * never leaves the server.
     *
     * @return array{appId: bool, keyFound: bool, keyValid: bool}
     */
    public function setupStatus(): array
    {
        $privateKey = $this->privateKey();
        $key = $privateKey === null ? false : openssl_pkey_get_private($privateKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return [
            'appId' => filled($this->appId),
            'keyFound' => $privateKey !== null,
            'keyValid' => $details !== false && $details['type'] === OPENSSL_KEYTYPE_RSA,
        ];
    }

    /**
     * Banks available in a country.
     *
     * @return list<array{name: string, country: string, logo?: string|null, maximum_consent_validity?: int|null, psu_types?: list<string>}>
     */
    public function aspsps(string $country): array
    {
        return $this->send('get', '/aspsps', ['country' => $country, 'psu_type' => 'personal'])->json('aspsps') ?? [];
    }

    /**
     * Start an authorization: the member must then be sent to the returned `url`.
     *
     * @return array{url: string, authorization_id: string}
     */
    public function startAuthorization(string $aspspName, string $country, string $state, string $redirectUrl, CarbonInterface $validUntil): array
    {
        return $this->send('post', '/auth', [
            'access' => ['valid_until' => $validUntil->toIso8601String()],
            'aspsp' => ['name' => $aspspName, 'country' => $country],
            'state' => $state,
            'redirect_url' => $redirectUrl,
            'psu_type' => 'personal',
        ])->json();
    }

    /**
     * Turn the code received on the redirect URL into a session with its accounts.
     *
     * @return array{session_id: string, accounts: list<array<string, mixed>>, aspsp: array{name: string, country: string}, access: array{valid_until: string}}
     */
    public function createSession(string $code): array
    {
        return $this->send('post', '/sessions', ['code' => $code])->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function session(string $sessionId): array
    {
        return $this->send('get', "/sessions/{$sessionId}")->json();
    }

    /**
     * Revoke the bank consent behind a session.
     */
    public function deleteSession(string $sessionId): void
    {
        $this->send('delete', "/sessions/{$sessionId}");
    }

    /**
     * Every transaction of an account since a date, following `continuation_key` pages.
     *
     * @param  array<string, string>  $psuHeaders  Headers describing the member when they are present (manual sync).
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountUid, CarbonInterface $dateFrom, array $psuHeaders = []): array
    {
        $transactions = [];
        $continuationKey = null;

        do {
            $page = $this->send('get', "/accounts/{$accountUid}/transactions", array_filter([
                'date_from' => $dateFrom->toDateString(),
                'continuation_key' => $continuationKey,
            ]), $psuHeaders)->json();

            array_push($transactions, ...($page['transactions'] ?? []));
            $continuationKey = $page['continuation_key'] ?? null;
        } while (filled($continuationKey));

        return $transactions;
    }

    /**
     * @param  array<string, string>  $psuHeaders
     * @return list<array{balance_amount: array{amount: string, currency: string}, balance_type: string, name?: string|null, reference_date?: string|null}>
     */
    public function balances(string $accountUid, array $psuHeaders = []): array
    {
        return $this->send('get', "/accounts/{$accountUid}/balances", [], $psuHeaders)->json('balances') ?? [];
    }

    /**
     * The signed JWT sent as bearer token.
     */
    public function token(): string
    {
        if (! $this->isConfigured()) {
            throw new EnableBankingException(__('EnableBanking is not configured: application ID or private key missing.'));
        }

        $now = now()->getTimestamp();
        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $this->appId];
        $claims = ['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => $now, 'exp' => $now + self::TOKEN_LIFETIME];

        $unsigned = $this->base64Url((string) json_encode($header)).'.'.$this->base64Url((string) json_encode($claims));

        $key = openssl_pkey_get_private((string) $this->privateKey());
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new EnableBankingException(__('The EnableBanking private key cannot be read.'));
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, array $data = [], array $headers = []): Response
    {
        $response = $this->request()->withHeaders($headers)->{$method}($path, $data);

        if ($response->failed()) {
            throw new EnableBankingException(
                (string) ($response->json('message') ?? __('EnableBanking responded :status.', ['status' => (string) $response->status()])),
                is_string($response->json('error')) ? $response->json('error') : null,
                $response->status(),
            );
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token())
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * The PEM key: from the environment in production (base64, the server has no
     * key file), otherwise from the file next to the app.
     */
    private function privateKey(): ?string
    {
        if (filled($this->privateKey)) {
            return base64_decode($this->privateKey, true) ?: null;
        }

        $path = str_starts_with($this->privateKeyPath, '/') ? $this->privateKeyPath : base_path($this->privateKeyPath);

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
