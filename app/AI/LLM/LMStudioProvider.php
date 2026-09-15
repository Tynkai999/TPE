<?php

namespace App\AI\LLM;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LMStudioProvider implements LLMProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly float $temperature = 0.7,
        private readonly int $maxTokens = 1024,
    ) {}

    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            // Optionnel : on peut forcer un outil spécifique avec 'tool_choice', mais on laisse auto par défaut
        }

        $response = Http::timeout(180)
            ->post(rtrim($this->baseUrl, '/') . '/v1/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "LM Studio a répondu avec une erreur HTTP {$response->status()} : " . $response->body()
            );
        }

        $message = $response->json('choices.0.message');

        return [
            'content'    => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
        ];
    }
}