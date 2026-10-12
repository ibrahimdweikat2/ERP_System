<?php

namespace App\Domains\Licensing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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

    private ?string $keyProblem = null;

    public function configured(): bool
    {
        return $this->problems() === [];
    }

    /**
     * What keeps the connection from working, one line per setting (empty when configured).
     * Shown by `php artisan erp:license-check`; never to company users.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $c = config('erp.license');
        $problems = [];
        foreach (['server_url' => 'LICENSEBOX_SERVER_URL', 'product_id' => 'LICENSEBOX_PRODUCT_ID', 'api_key' => 'LICENSEBOX_API_KEY'] as $key => $env) {
            if (blank($c[$key] ?? null)) {
                $problems[] = "$env is empty.";
            }
        }
        if ($this->publicKey() === null) {
            $problems[] = $this->keyProblem ?? 'No public key.';
        }

        return $problems;
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

    /**
     * The server's public key: the file at public_key_path, or, when that file does not exist
     * yet, downloaded once from public_key_url and saved there. A downloaded key is accepted
     * only if its fingerprint equals key_id (LicenseBox Settings -> Key ID), so a wrong or
     * forged download is never trusted. A file is checked against key_id too when one is set.
     */
    private function publicKey(): ?string
    {
        $path = (string) config('erp.license.public_key_path');
        $keyId = strtolower(trim((string) config('erp.license.key_id')));
        if (isset($this->pem[$path])) {
            return $this->pem[$path];
        }
        $this->keyProblem = null;

        if ($path !== '' && is_file($path)) {
            if (! is_readable($path)) {
                $this->keyProblem = "Public key file is not readable by the web server user: $path";

                return null;
            }
            $pem = (string) file_get_contents($path);
            // A saved key from before the server's keypair was regenerated: fetch the current one.
            if ($keyId !== '' && filled(config('erp.license.public_key_url')) && substr(hash('sha256', $pem), 0, 16) !== $keyId) {
                $pem = $this->download($path, $keyId);
                if ($pem === null) {
                    return null;
                }
            }
        } else {
            $pem = $this->download($path, $keyId);
            if ($pem === null) {
                return null;
            }
        }

        if (! str_contains($pem, 'PUBLIC KEY') || openssl_pkey_get_public($pem) === false) {
            $this->keyProblem = "Not a PEM public key: $path";

            return null;
        }
        if ($keyId !== '' && ($actual = substr(hash('sha256', $pem), 0, 16)) !== $keyId) {
            $this->keyProblem = "Public key fingerprint $actual does not match LICENSEBOX_KEY_ID $keyId.";

            return null;
        }

        return $this->pem[$path] = $pem;
    }

    private function download(string $path, string $keyId): ?string
    {
        $url = (string) config('erp.license.public_key_url');
        if ($url === '') {
            $this->keyProblem = "Public key file not found: $path (set LICENSEBOX_PUBLIC_KEY_PATH to an existing file, or LICENSEBOX_PUBLIC_KEY_URL with LICENSEBOX_KEY_ID).";

            return null;
        }
        if ($keyId === '') {
            $this->keyProblem = 'LICENSEBOX_KEY_ID is required with LICENSEBOX_PUBLIC_KEY_URL: a downloaded key is trusted only when it matches it.';

            return null;
        }
        // An unreachable URL is retried at most every five minutes, not on every request.
        if ($failure = Cache::get('licensebox.public_key.failure')) {
            $this->keyProblem = $failure;

            return null;
        }
        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
            $pem = $response->successful() ? $response->body() : null;
            $failure = $pem === null ? "Public key download failed (HTTP {$response->status()}): $url" : null;
        } catch (ConnectionException $e) {
            $pem = null;
            $failure = "Public key download failed: {$e->getMessage()}";
        }
        if ($pem !== null && substr(hash('sha256', $pem), 0, 16) !== $keyId) {
            $pem = null;
            $failure = 'Downloaded public key fingerprint '.substr(hash('sha256', (string) $response->body()), 0, 16)." does not match LICENSEBOX_KEY_ID $keyId.";
        }
        if ($pem === null) {
            Cache::put('licensebox.public_key.failure', $failure, now()->addMinutes(5));
            $this->keyProblem = $failure;

            return null;
        }
        // Saved so later requests read the file; if storage is not writable the key still works for this request.
        if ($path !== '' && (is_dir(dirname($path)) || @mkdir(dirname($path), 0750, true))) {
            @file_put_contents($path, $pem, LOCK_EX);
        }

        return $pem;
    }
}
