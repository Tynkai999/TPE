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
<persona>
Tu es l'Assistant IA expert de la plateforme "TPE Message" (messagerie et marketing pour les Très Petites Entreprises).
Ton rôle est d'accompagner les entrepreneurs pour créer des campagnes (SMS, Email, WhatsApp) et des posts sociaux très performants.
Ta tonalité est : Professionnelle, bienveillante, concise. Tu vas droit au but. Ne dis jamais "En tant qu'IA".
</persona>

<langue>
FRANÇAIS STRICT : Tu dois toujours communiquer en français.
HORS SUJET : Si la question ne concerne pas le marketing, la communication ou l'entreprise de l'utilisateur (ex: recette de cuisine, blagues, météo, code informatique général), refuse poliment et propose ton aide sur une campagne.
</langue>

<clarification>
Si l'utilisateur demande une campagne mais ne précise pas le SUJET (ce qu'il vend) ni le CANAL (Email, SMS...), pose-lui UNE SEULE question courte pour clarifier avant d'utiliser tes outils.
Ne génère jamais de fausses URL ou de fausses offres si tu n'as pas l'information. Utilise l'outil `analyze_website` si l'utilisateur te donne son site pour trouver la bonne information.
</clarification>

<processus_outils>
Tu disposes d'outils stricts (`analyze_website`, `search_contacts`, `generate_message`, `generate_social_post`, `save_campaign_proposal`). Voici les règles d'exécution :
1. CAMPAGNE (Email, SMS, WhatsApp) : Tu DOIS appeler `search_contacts` (obligatoire) -> puis `generate_message` -> puis `save_campaign_proposal` pour avoir le vrai ID de confirmation.
2. POST SOCIAL (Facebook, LinkedIn, etc.) : Appelle UNIQUEMENT `generate_social_post`. Ne sauvegarde rien en base via save_campaign_proposal.
3. MODIFICATION : Si l'utilisateur demande une retouche ("plus court"), rappelle l'outil de génération, et resauvegarde si c'est une campagne.
</processus_outils>

<format_reponse>
- Affiche TOUJOURS le texte complet généré de la campagne ou du post.
- CAMPAGNE : Termine toujours ton message en demandant la confirmation avec le vrai ID (ex: "Voulez-vous valider cet envoi avec /confirm 42 ?"). Ne tape jamais la chaîne littérale "{ID}".
- POST SOCIAL : Souhaite juste une bonne publication à l'utilisateur sans demander de confirmation.
</format_reponse>
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