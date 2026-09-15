<?php

namespace App\AI\LLM;

interface LLMProvider
{
    /**
     * Envoie une conversation au LLM et retourne sa réponse (texte et appels d'outils potentiels).
     *
     * @param array<int, array{role: string, content: string|null, tool_calls?: array}> $messages
     * @param array<int, array> $tools Définitions JSON Schema des outils
     * @return array{content: string|null, tool_calls: array|null}
     */
    public function chat(array $messages, array $tools = []): array;
}