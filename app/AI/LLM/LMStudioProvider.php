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

    public function chat(array $messages): string
    {
        $response = Http::timeout(180)
            ->post(rtrim($this->baseUrl, '/') . '/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $this->temperature,
                'max_tokens'  => $this->maxTokens,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "LM Studio a répondu avec une erreur : " . $response->body()
            );
        }

        return $response->json('choices.0.message.content') ?? '';
    }
}