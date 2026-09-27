<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Thin RouterOS v7 REST client (/rest/...). RouterOS v6 has no REST API.
class MikroTikClient
{
    public function __construct(private Router $router)
    {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    public function create(string $path, array $data): array
    {
        return $this->send('put', $path, $data);
    }

    public function update(string $path, string $id, array $data): array
    {
        return $this->send('patch', $path . '/' . $this->id($id), $data);
    }

    public function delete(string $path, string $id): void
    {
        $this->send('delete', $path . '/' . $this->id($id));
    }

    // RouterOS commands that are not plain menus (ping, monitor-traffic...): POST /rest/<command>
    public function run(string $path, array $data = [], int $timeout = 8): array
    {
        return $this->send('post', $path, $data, $timeout);
    }

    // Exact-match lookup, e.g. first('/ppp/secret', ['name' => 'user1'])
    public function first(string $path, array $where): ?array
    {
        $rows = $this->get($path, $where);
        return $rows[0] ?? null;
    }

    // RouterOS ids look like "*1A"; they must be sent as-is (an encoded "%2A1A" is rejected).
    private function id(string $id): string
    {
        if (! preg_match('/^\*?[0-9A-Fa-f]+$/', $id)) {
            throw new RuntimeException("Invalid RouterOS id {$id}");
        }
        return $id;
    }

    private function send(string $method, string $path, array $data = [], int $timeout = 8): array
    {
        $request = Http::withBasicAuth($this->router->username, $this->router->password)
            ->timeout($timeout)
            ->connectTimeout(4)
            ->acceptJson()
            ->withOptions(['verify' => false]); // routers commonly use self-signed certificates

        $url = $this->router->baseUrl() . '/' . ltrim($path, '/');
        try {
            $response = $method === 'get' ? $request->get($url, $data) : $request->{$method}($url, $data);
        } catch (\Throwable $e) {
            throw new RuntimeException("Router {$this->router->name} unreachable: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $body = $response->json();
            $detail = $body['detail'] ?? $body['message'] ?? $response->body();
            throw new RuntimeException("Router {$this->router->name} {$response->status()}: {$detail}");
        }
        $json = $response->json();
        return is_array($json) ? $json : [];
    }
}
