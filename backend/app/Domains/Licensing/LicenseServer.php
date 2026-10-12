<?php

namespace App\Domains\Licensing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the LicenseBox server, as its PHP client SDK does: every response must carry
 * a valid RSA signature from the server's public key and echo this request's nonce, so a
 * fake server, a man-in-the-middle or a replayed response is rejected.
 */
class LicenseServer
{
    /** @var array<string, string> */
    private array $pem = [];

    public function configured(): bool
    {
        $c = config('erp.license');

        return filled($c['server_url']) && filled($c['product_id']) && filled($c['api_key']) && $this->publicKey() !== null;
    }

    /**
     * POST to /api/v1/{endpoint}. Returns the decoded response (status false included).
     *
     * @return array{status: bool, message: string, data: mixed, reason?: string}
     *
     * @throws RuntimeException when the server cannot be reached or its answer cannot be trusted
     */
    public function call(string $endpoint, array $data = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('License server is not configured.');
        }
        $nonce = bin2hex(random_bytes(16));
        try {
            $response = Http::acceptJson()->asJson()
                ->timeout((int) config('erp.license.timeout', 15))->connectTimeout(10)
                ->withHeaders(['X-API-KEY' => config('erp.license.api_key')])
                ->post(rtrim(config('erp.license.server_url'), '/').'/api/v1/'.$endpoint, ['product_id' => config('erp.license.product_id'), 'nonce' => $nonce, ...$data]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Connection to the license server failed: '.$e->getMessage(), 0, $e);
        }

        $body = $response->body();
        if (! $this->verifySignature($body, (string) $response->header('X-LicenseBox-Signature'))) {
            throw new RuntimeException('The license server response could not be verified.');
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! isset($decoded['status'])) {
            throw new RuntimeException('Invalid response from the license server.');
        }
        if (! hash_equals($nonce, (string) ($decoded['nonce'] ?? ''))) {
            throw new RuntimeException('The license server response does not match the request.');
        }

        return $decoded + ['data' => null, 'message' => ''];
    }

    /**
     * The payload of a server-signed local key, or null when it is forged, belongs to another
     * key or product, or was issued for another domain.
     *
     * @return array{license_hash: string, product_id: string, instance_id: string, domain: ?string, issued_at: int, next_check_at: int, valid_until: int}|null
     */
    public function decodeLocalKey(string $token, string $licenseKey): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || ! $this->verifySignature($parts[0], $parts[1])) {
            return null;
        }
        $payload = json_decode((string) base64_decode($parts[0], true), true);
        if (! is_array($payload) || ! is_string($payload['license_hash'] ?? null)
            || ! hash_equals($payload['license_hash'], hash('sha256', strtoupper(trim($licenseKey))))
            || ($payload['product_id'] ?? null) !== config('erp.license.product_id')
            || ! isset($payload['instance_id'], $payload['next_check_at'], $payload['valid_until'])) {
            return null;
        }
        $domain = $this->domain();
        if (($payload['domain'] ?? null) !== null && $domain !== null && $payload['domain'] !== $domain) {
            return null;
        }

        return $payload;
    }

    /** This installation's domain, normalized as LicenseBox stores it. */
    public function domain(): ?string
    {
        $domain = config('erp.license.domain') ?: parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        $domain = strtolower(trim((string) $domain));
        $domain = (string) preg_replace(['#^[a-z][a-z0-9+.-]*://#', '#[/?\#].*$#', '#:\d+$#', '#^www\.#'], '', $domain);

        return $domain === '' ? null : $domain;
    }

    private function verifySignature(string $data, string $signature): bool
    {
        $key = $this->publicKey();
        $raw = base64_decode($signature, true);

        return $key !== null && $raw !== false && $raw !== '' && openssl_verify($data, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function publicKey(): ?string
    {
        $path = (string) config('erp.license.public_key_path');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $pem = $this->pem[$path] ??= (string) file_get_contents($path);

        return str_contains($pem, 'PUBLIC KEY') ? $pem : null;
    }
}
