<?php

namespace App\AI\Tools;

use App\AI\Http\CollaboratorApiClient;
use App\AI\Http\CollaboratorTokenManager;
use InvalidArgumentException;

class SendCampaignTool implements Tool
{
    public function __construct(
        private readonly CollaboratorApiClient $client,
        private readonly CollaboratorTokenManager $tokenManager,
    ) {}

    public function name(): string
    {
        return 'send_campaign';
    }

    public function description(): string
    {
        return "Déclenche l'envoi d'une campagne brouillon confirmée.";
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $userId = $arguments['collaborator_user_id'] ?? null;
        $campaignId = $arguments['campaign_id'] ?? null;

        if (!is_string($userId) || $userId === '' || !is_string($campaignId) || $campaignId === '') {
            throw new InvalidArgumentException('Le compte collaborateur et la campagne sont obligatoires.');
        }

        return $this->client->post(
            "/campaigns/{$campaignId}/send",
            [],
            $this->tokenManager->getValidAccessToken($userId),
        );
    }
}