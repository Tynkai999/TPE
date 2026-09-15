<?php

namespace App\AI\LLM;

use App\AI\Exceptions\LLMUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResilientLLMProvider implements LLMProvider
{
    /**
     * @param array<string, mixed> $fallbackConfig
     */
    public function __construct(
        private readonly LLMProvider $primaryProvider,
        private readonly array $fallbackConfig = [],
    ) {}

    /**
     * Tente d'appeler le modèle local prioritaire.
     * Si LM Studio est éteint, bascule sur le secours ou lève LLMUnavailableException.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @throws LLMUnavailableException
     */
    public function chat(array $messages, array $tools = []): array
    {
        try {
            return $this->primaryProvider->chat($messages, $tools);
        } catch (ConnectionException | Throwable $primaryError) {
            Log::warning("Le fournisseur LLM principal (LM Studio local) a échoué : {$primaryError->getMessage()}");

            // Si un secours est activé et configuré
            if (!empty($this->fallbackConfig['enabled']) && !empty($this->fallbackConfig['api_key'])) {
                try {
                    return $this->callFallback($messages, $tools);
                } catch (Throwable $fallbackError) {
                    Log::error("Le fournisseur LLM de secours a également échoué : {$fallbackError->getMessage()}");
                }
            }

            throw new LLMUnavailableException(
                "Le moteur d'intelligence artificielle local n'est pas joignable (LM Studio). " . $primaryError->getMessage(),
                0,
                $primaryError
            );
        }
    }

    /**
     * Appel du fournisseur Cloud de secours (API standard compatible OpenAI).
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<int, array> $tools
     * @return array{content: string|null, tool_calls: array|null}
     */
    private function callFallback(array $messages, array $tools = []): array
    {
        $baseUrl = rtrim((string) ($this->fallbackConfig['base_url'] ?? 'https://api.mistral.ai/v1'), '/');
        $apiKey = (string) ($this->fallbackConfig['api_key'] ?? '');
        $model = (string) ($this->fallbackConfig['model'] ?? 'mistral-small-latest');
        $timeout = (int) ($this->fallbackConfig['timeout'] ?? 30);

        $payload = [
            'model'    => $model,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->post("{$baseUrl}/chat/completions", $payload);

        if ($response->failed()) {
            throw new LLMUnavailableException("L'API de secours a retourné une erreur HTTP {$response->status()} : {$response->body()}");
        }

        $message = $response->json('choices.0.message');

        return [
            'content'    => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
        ];
    }
}

