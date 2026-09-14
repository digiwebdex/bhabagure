<?php

namespace App\Services\Payments\SslCommerz;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpSslCommerzGateway implements SslCommerzGateway
{
    public function __construct(
        private readonly string $host,
        private readonly string $storeId,
        private readonly string $storePassword,
        private readonly int $timeout,
    ) {}

    public function createSession(array $fields): array
    {
        return $this->decode(fn () => Http::asForm()->timeout($this->timeout)
            ->post("{$this->host}/gwprocess/v4/api.php", $fields + ['store_id' => $this->storeId, 'store_passwd' => $this->storePassword]));
    }

    public function validate(string $valId): array
    {
        return $this->decode(fn () => Http::timeout($this->timeout)->get("{$this->host}/validator/api/validationserverAPI.php", [
            'val_id' => $valId, 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json', 'v' => 1,
        ]));
    }

    public function queryByTransactionId(string $tranId): array
    {
        return $this->decode(fn () => Http::timeout($this->timeout)->get("{$this->host}/validator/api/merchantTransIDvalidationAPI.php", [
            'tran_id' => $tranId, 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json',
        ]));
    }

    /** SSLCommerz's documented scheme: md5 over the listed keys plus md5(store password), sorted by key. */
    public function verifiesSignature(array $post): bool
    {
        if (empty($post['verify_key']) || empty($post['verify_sign'])) {
            return false;
        }

        $data = [];
        foreach (explode(',', (string) $post['verify_key']) as $key) {
            $data[$key] = (string) ($post[$key] ?? '');
        }
        $data['store_passwd'] = md5($this->storePassword);
        ksort($data);
        $string = implode('&', array_map(fn ($key, $value) => "{$key}={$value}", array_keys($data), $data));

        return hash_equals(md5($string), (string) $post['verify_sign']);
    }

    /** @return array<string, mixed> */
    private function decode(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable($e->getMessage(), previous: $e);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new GatewayUnavailable("SSLCommerz answered HTTP {$response->status()}");
        }

        return $response->json();
    }
}
