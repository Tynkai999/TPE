<?php

namespace App\AI\Orchestrator;

use App\AI\LLM\LLMProvider;
use App\AI\Tools\ToolExecutor;
use App\Models\AiCommand;

use App\AI\Tools\ToolRegistry;

class AgentOrchestrator
{
    public function __construct(
        private readonly LLMProvider $llm,
        private readonly ToolExecutor $tools,
        private readonly ToolRegistry $registry,
    ) {}

    /**
     * Point d'entrée de l'agent : reçoit le message de l'utilisateur,
     * analyse l'intention et exécute les outils appropriés.
     *
     * @param array<int, array{role: string, content: string}> $conversation
     */
    public function handle(
        string $userMessage,
        ?string $collaboratorUserId = null,
        array $conversation = [],
    ): string {
        if (!$collaboratorUserId) {
            return "Votre compte local n’est pas encore relié à un compte collaborateur. Reliez-le avant de pouvoir utiliser l'assistant.";
        }

        $systemPrompt = <<<PROMPT
Tu es TPE AI Assistant, l'assistant IA d'une plateforme marketing pour TPE.
Ton but est de concevoir des campagnes (SMS, Email, WhatsApp) et des posts pour les réseaux sociaux.

Tu disposes d'outils (tools) stricts :
1. `analyze_website` : pour lire le site web de l'utilisateur.
2. `search_contacts` : pour trouver sa base client et cibler l'audience.
3. `generate_message` : pour rédiger le texte d'une campagne email/sms/whatsapp.
4. `generate_social_post` : pour rédiger un post (Facebook, Instagram, LinkedIn, Twitter).
5. `save_campaign_proposal` : pour sauvegarder la proposition (uniquement pour email/sms/whatsapp) et obtenir un ID.

RÈGLES ABSOLUES :
- Quand on te demande une campagne classique (email, sms, whatsapp) : TU DOIS appeler `search_contacts` (obligatoire), `generate_message`, puis `save_campaign_proposal` pour avoir l'ID.
- Quand on te demande un post réseaux sociaux : Tu appelles uniquement `generate_social_post` et tu l'affiches. Inutile d'appeler `save_campaign_proposal` ou `search_contacts` pour un post social, car ça ne s'envoie pas via la base client.
- Si l'utilisateur demande une modification (ex: "fais plus court"), tu DOIS rappeler l'outil de génération de message (`generate_message` ou `generate_social_post`) et si c'est une campagne, resauvegarder.
- Dans ta réponse finale, affiche TOUJOURS le texte complet généré (campagne ou post).
- Si c'est une campagne classique, termine ta phrase en donnant l'instruction pour confirmer avec le vrai ID renvoyé par l'outil save_campaign_proposal (exemple : "Voulez-vous confirmer avec /confirm 45 ?"). Ne tape JAMAIS la chaîne littérale "{ID}".
- Si c'est un post social, souhaite-lui juste une bonne publication.
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($conversation as $msg) {
            $messages[] = $msg;
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // On récupère les définitions de tous les outils autorisés
        $allowedTools = ['analyze_website', 'search_contacts', 'generate_message', 'save_campaign_proposal', 'generate_social_post'];
        $toolsDef = [];
        foreach ($this->registry->all() as $tool) {
            if (in_array($tool->name(), $allowedTools, true)) {
                $toolsDef[] = $tool->getDefinition();
            }
        }

        $state = ['contact_ids' => []];
        $iterations = 0;

        while ($iterations < 5) {
            $response = $this->llm->chat($messages, $toolsDef);

            $assistantMessage = [
                'role' => 'assistant',
                'content' => $response['content'] ?? null,
            ];
            if (!empty($response['tool_calls'])) {
                $assistantMessage['tool_calls'] = $response['tool_calls'];
            }
            $messages[] = $assistantMessage;

            if (empty($response['tool_calls'])) {
                return $response['content'] ?? '';
            }

            foreach ($response['tool_calls'] as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                if ($collaboratorUserId) {
                    $arguments['collaborator_user_id'] = $collaboratorUserId;
                }

                if ($toolName === 'save_campaign_proposal') {
                    $arguments['contact_ids'] = $state['contact_ids'];
                }

                try {
                    $result = $this->tools->execute($toolName, $arguments);

                    if ($toolName === 'search_contacts') {
                        $items = $this->extractContactList($result);
                        $state['contact_ids'] = $this->contactIds($items);
                        // Simplifier le retour pour le LLM
                        $result = ['count' => count($state['contact_ids']), 'message' => "Trouvé " . count($state['contact_ids']) . " contacts."];
                    }
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result),
                ];
            }
            
            $iterations++;
        }

        return "Désolé, j'ai eu besoin de trop réfléchir et j'ai dû m'arrêter. Pouvez-vous préciser votre demande ?";
    }

    private function extractContactList(mixed $response): array
    {
        $data = is_array($response) ? ($response['data'] ?? $response) : $response;

        if (is_array($data) && isset($data['contacts']) && is_array($data['contacts'])) {
            $data = $data['contacts'];
        }

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) ? array_values($data) : [];
    }

    private function contactIds(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): mixed => is_array($item) ? ($item['id'] ?? null) : null,
            $items,
        ), static fn (mixed $id): bool => is_string($id) && $id !== ''));
    }
}