<?php

namespace App\AI\Tools;

use App\AI\Http\CollaboratorApiClient;
use App\AI\Http\CollaboratorTokenManager;
use InvalidArgumentException;

class SearchContactsTool implements Tool
{
    public function __construct(
        private readonly CollaboratorApiClient $client,
        private readonly CollaboratorTokenManager $tokenManager,
    ) {}

    public function name(): string
    {
        return 'search_contacts';
    }

    public function description(): string
    {
        return 'Recherche les contacts du collaborateur avec des filtres CRM (ex: clients inactifs).';
    }

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'inactive_days' => [
                            'type' => 'integer',
                            'description' => 'Nombre de jours d\'inactivité pour filtrer les clients (ex: 30)'
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $userId = $arguments['collaborator_user_id'] ?? null;

        if (!is_string($userId) || $userId === '') {
            throw new InvalidArgumentException('Le contexte collaborateur est obligatoire.');
        }

        $accessToken = $this->tokenManager->getValidAccessToken($userId);
        unset($arguments['collaborator_user_id']);

        return $this->client->get('/contacts', $arguments, $accessToken);
    }
}