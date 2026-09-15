<?php

namespace App\AI\Tools;

use App\Models\AiCommand;

class SaveCampaignProposalTool implements Tool
{
    public function name(): string
    {
        return 'save_campaign_proposal';
    }

    public function description(): string
    {
        return 'Sauvegarde la proposition de campagne en base de données pour permettre à l\'utilisateur de la valider.';
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
                        'message' => ['type' => 'string', 'description' => 'Le contenu exact du message de la campagne.'],
                        'channel' => ['type' => 'string', 'enum' => ['sms', 'email', 'whatsapp'], 'description' => 'Le canal (sms, email, whatsapp)'],
                        'audience' => ['type' => 'string', 'description' => 'Description de la cible (ex: tous les clients, clients inactifs 30 jours)'],
                    ],
                    'required' => ['message', 'channel', 'audience'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $userId = $arguments['collaborator_user_id'] ?? null;
        if (!$userId) {
            return ['error' => 'collaborator_user_id est manquant.'];
        }

        $contactIds = $arguments['contact_ids'] ?? [];

        $command = AiCommand::create([
            'collaborator_user_id' => $userId,
            'intent' => 'DRAFT_MESSAGE',
            'parameters' => [
                'message' => $arguments['message'],
                'message_channel' => $arguments['channel'],
                'audience' => $arguments['audience'],
                'contact_ids' => $contactIds,
            ],
            'status' => 'PROPOSED',
            'estimated_recipients' => $arguments['estimated_recipients'] ?? count($contactIds),
        ]);

        return [
            'success' => true,
            'proposal_id' => $command->id,
            'info' => 'Campagne sauvegardée avec succès. Demandez à l\'utilisateur de confirmer via la commande /confirm ' . $command->id
        ];
    }
}
