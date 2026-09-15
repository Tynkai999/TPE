<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fournisseur Principal : LM Studio Local (Machine de travail)
    |--------------------------------------------------------------------------
    | Par défaut, l'agent interroge LM Studio tournant sur votre machine.
    | Timeout adapté pour permettre au GPU/CPU local de générer le texte.
    */
    'lm_studio' => [
        'base_url'    => env('LM_STUDIO_BASE_URL', 'http://host.docker.internal:1234'),
        'model'       => env('LM_STUDIO_MODEL', 'qwen2.5-7b-instruct'),
        'timeout'     => (int) env('LM_STUDIO_TIMEOUT', 120),
        'temperature' => (float) env('LM_STUDIO_TEMPERATURE', 0.7),
        'max_tokens'  => (int) env('LM_STUDIO_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fournisseur de Secours (Fallback Optionnel)
    |--------------------------------------------------------------------------
    | Si LM Studio est éteint ou inaccessible, le système peut basculer
    | de façon transparente sur une API distante (ex: Mistral, Groq, OpenAI).
    | Désactivé par défaut.
    */
    'fallback' => [
        'enabled'     => (bool) env('AI_FALLBACK_ENABLED', false),
        'base_url'    => env('AI_FALLBACK_BASE_URL', 'https://api.mistral.ai/v1'),
        'api_key'     => env('AI_FALLBACK_API_KEY', ''),
        'model'       => env('AI_FALLBACK_MODEL', 'mistral-small-latest'),
        'timeout'     => (int) env('AI_FALLBACK_TIMEOUT', 30),
    ],
];