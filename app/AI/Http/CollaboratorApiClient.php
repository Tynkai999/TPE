<?php

namespace App\AI\Http;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CollaboratorApiClient
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    public function get(string $path, array $query = [], ?string $accessToken = null): array
    {
        return $this->request('get', $path, $accessToken, query: $query);
    }

    public function post(string $path, array $body = [], ?string $accessToken = null): array
    {
        return $this->request('post', $path, $accessToken, body: $body);
    }

    private function request(string $method, string $path, ?string $accessToken, array $query = [], array $body = []): array
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson();

        if ($accessToken) {
            $request = $request->withToken($accessToken);
        }

        $response = match ($method) {
            'get'  => $request->get($path, $query),
            'post' => $request->post($path, $body),
            default => throw new RuntimeException("Méthode HTTP non supportée : {$method}"),
        };

        if ($response->failed()) {
            throw new RuntimeException(
                "Erreur API collaborateur [{$response->status()}] sur {$path} : " . $response->body()
            );
        }

        return $response->json() ?? [];
    }
}