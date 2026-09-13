<?php

namespace App\AI\Tools;

use App\AI\Http\CollaboratorApiClient;
use App\AI\Http\CollaboratorTokenManager;
use InvalidArgumentException;
use RuntimeException;

class CreateCampaignTool implements Tool
{
    public function __construct(
        private readonly CollaboratorApiClient $client,
        private readonly CollaboratorTokenManager $tokenManager,
    ) {}

    public function name(): string
    {
        return 'create_campaign';
    }

    public function description(): string
    {
        return 'Crée un groupe ciblé puis une campagne en brouillon.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     *
     * Paramètres reconnus :
     *   - collaborator_user_id (string, obligatoire)
     *   - contact_ids          (string[], obligatoire)
     *   - name                 (string, obligatoire)
     *   - content              (string, obligatoire)
     *   - message_channel      (string, défaut 'sms')
     *   - existing_group_id    (string|null) — si fourni, le groupe est réutilisé sans en créer un nouveau.
     *                          Permet la reprise idempotente après un échec partiel (groupe créé, campagne échouée).
     */
    public function execute(array $arguments): array
    {
        $userId          = $arguments['collaborator_user_id'] ?? null;
        $contactIds      = $arguments['contact_ids'] ?? [];
        $name            = $arguments['name'] ?? null;
        $content         = $arguments['content'] ?? null;
        $channel         = $arguments['message_channel'] ?? 'sms';
        $existingGroupId = $arguments['existing_group_id'] ?? null;

        if (!is_string($userId) || $userId === '' || !is_array($contactIds) || $contactIds === []) {
            throw new InvalidArgumentException('Le compte collaborateur et les contacts ciblés sont obligatoires.');
        }

        if (!is_string($name) || $name === '' || !is_string($content) || $content === '') {
            throw new InvalidArgumentException('Le nom et le contenu de la campagne sont obligatoires.');
        }

        if (!in_array($channel, ['sms', 'email', 'whatsapp'], true)) {
            throw new InvalidArgumentException('Le canal doit être sms, email ou whatsapp.');
        }

        $accessToken = $this->tokenManager->getValidAccessToken($userId);

        $contactIds = $this->excludeOptedOut($contactIds, $userId, $accessToken);
        if ($contactIds === []) {
            throw new RuntimeException('Tous les contacts ciblés ont fait l\'objet d\'un opt-out.');
        }

        // ── Groupe ──────────────────────────────────────────────────────────────
        // Si un groupe a déjà été créé lors d'une tentative précédente (reprise après
        // échec de /campaigns), on le réutilise pour éviter la violation de contrainte
        // unique « groups_user_id_name_unique » côté API collaborateur.
        if (is_string($existingGroupId) && $existingGroupId !== '') {
            $groupId = $existingGroupId;
        } else {
            $groupResponse = $this->client->post('/groups', [
                // Suffixe unique : garantit l'unicité même sans reprise (ex : deux propositions
                // distinctes avec le même nom de base, ou une reprise sans group_id connu).
                'name' => $name . ' - ' . uniqid('audience-', false),
            ], $accessToken);
            $group   = $this->unwrapResource($groupResponse, 'group');
            $groupId = $group['id'] ?? null;

            if (!is_string($groupId) || $groupId === '') {
                throw new RuntimeException("L'API collaborateur n'a pas retourné l'identifiant du groupe.");
            }

            // Peuple le groupe avec les contacts filtrés
            $this->client->post("/groups/{$groupId}/contacts", [
                'contact_ids' => array_values($contactIds),
            ], $accessToken);
        }

        // ── Campagne ────────────────────────────────────────────────────────────
        $campaignResponse = $this->client->post('/campaigns', [
            'name'            => $name,
            'content'         => $content,
            'channels'        => [$channel],
            'group_id'        => $groupId,
        ], $accessToken);

        $campaign = $this->unwrapResource($campaignResponse, 'campaign');

        if (!isset($campaign['id'])) {
            throw new RuntimeException("L'API collaborateur n'a pas retourné l'identifiant de la campagne.");
        }

        return [
            'group_id' => $groupId,
            'campaign' => $campaign,
        ];
    }

    /**
     * Retire les contacts ayant fait l'objet d'un opt-out avant toute création de campagne.
     *
     * @param array<int, string> $contactIds
     * @return array<int, string>
     */
    private function excludeOptedOut(array $contactIds, string $userId, string $accessToken): array
    {
        $optOuts = $this->client->get('/opt-outs', [], $accessToken);
        $items = $this->unwrapResource($optOuts, 'opt_outs');

        // Champ supposé "contact_id" : à confirmer avec la documentation exacte de l'API collaborateur.
        $optedOutIds = array_values(array_filter(array_map(
            static fn (mixed $item): mixed => is_array($item) ? ($item['contact_id'] ?? null) : null,
            $items,
        ), static fn (mixed $id): bool => is_string($id) && $id !== ''));

        return array_values(array_diff($contactIds, $optedOutIds));
    }

    /**
     * Déballe une réponse API collaborateur, qui peut être un objet direct sous "data"
     * (mock/tests) ou une ressource nommée imbriquée : {data:{<resourceKey>: ... }}
     * ou paginée {data:{<resourceKey>:{data:[...]}}}.
     *
     * @return array<int|string, mixed>
     */
    private function unwrapResource(mixed $response, string $resourceKey): array
    {
        $data = is_array($response) ? ($response['data'] ?? $response) : [];

        if (is_array($data) && isset($data[$resourceKey]) && is_array($data[$resourceKey])) {
            $data = $data[$resourceKey];
        }

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) ? $data : [];
    }
}