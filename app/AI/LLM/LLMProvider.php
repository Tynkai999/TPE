<?php

namespace App\AI\LLM;

interface LLMProvider
{
    /**
     * Envoie une conversation au LLM et retourne sa réponse.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *        Exemple : [['role' => 'system', 'content' => '...'], ['role' => 'user', 'content' => '...']]
     */
    public function chat(array $messages): string;
}