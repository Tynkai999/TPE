<?php

namespace App\AI\Orchestrator;

use App\AI\LLM\LLMProvider;
use App\AI\Tools\ToolExecutor;
use App\Models\AiCommand;

class AgentOrchestrator
{
    public function __construct(
        private readonly LLMProvider $llm,
        private readonly ToolExecutor $tools,
        private readonly CommandParser $parser,
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
        $parsedCommand = $this->parser->parse($userMessage, $conversation);
        $intent = $parsedCommand['intent'] ?? null;

        // 1. Gestion de l'analyse de site web et proposition de campagne ciblée
        if ($intent === 'analyze_website' && !empty($parsedCommand['url'])) {
            if (!$collaboratorUserId) {
                return "Votre compte local n’est pas encore relié à un compte collaborateur. Reliez-le avant de pouvoir analyser votre site et cibler vos contacts.";
            }

            if (($parsedCommand['channel'] ?? 'unknown') === 'unknown') {
                return "Sur quel canal souhaitez-vous envoyer cette campagne ? (SMS, Email ou WhatsApp) ?";
            }

            // Exécution du tool d'analyse de site web
            $analysis = $this->tools->execute('analyze_website', [
                'url'                  => $parsedCommand['url'],
                'collaborator_user_id' => $collaboratorUserId,
            ]);

            $businessName = $analysis['business_name'] ?? 'votre commerce';
            $keyOffering = !empty($analysis['key_offerings'][0]) ? $analysis['key_offerings'][0] : ($analysis['activity_sector'] ?? 'nos services');
            $brandTone = $analysis['brand_tone'] ?? 'chaleureux et professionnel';

            // Détermination de l'audience ciblée (tous les clients, inactifs ou autre segment)
            $audienceType = $parsedCommand['audience'] ?? 'all';
            $inactiveDays = $parsedCommand['inactive_days'] ?? null;

            $searchArgs = ['collaborator_user_id' => $collaboratorUserId];
            if ($audienceType === 'inactive' && $inactiveDays !== null) {
                $searchArgs['inactive_days'] = $inactiveDays;
                $audienceLabel = "clients inactifs depuis {$inactiveDays} jours";
            } else {
                $audienceLabel = "l'ensemble de vos clients";
            }

            // Récupération des contacts dans le CRM collaborateur
            $contacts = $this->tools->execute('search_contacts', $searchArgs);
            $items = $this->extractContactList($contacts);
            $count = count($items);

            // Rédaction personnalisée du message marketing
            $channel = $parsedCommand['channel'] ?? 'sms';
            $offerDescription = "Mise en avant de {$keyOffering}";

            $generated = $this->tools->execute('generate_message', [
                'business_name'      => $businessName,
                'key_offering'       => $keyOffering,
                'offer'              => $offerDescription,
                'audience'           => $audienceLabel,
                'tone'               => $brandTone,
                'channel'            => $channel,
                'activity_sector'    => $analysis['activity_sector'] ?? null,
                'key_offerings_list' => $analysis['key_offerings'] ?? [],
            ]);

            // Persistance de la commande au statut PROPOSED (validation humaine obligatoire)
            $command = AiCommand::create([
                'collaborator_user_id' => $collaboratorUserId,
                'intent'               => 'DRAFT_MESSAGE',
                'parameters'           => [
                    'website_url'          => $parsedCommand['url'],
                    'business_name'        => $businessName,
                    'key_offering'         => $keyOffering,
                    'offer'                => $offerDescription,
                    'audience'             => $audienceLabel,
                    'message'              => $generated['message'],
                    'contact_ids'          => $this->contactIds($items),
                    'message_channel'      => $channel,
                ],
                'status'               => 'PROPOSED',
                'estimated_recipients' => $count,
            ]);

            $offeringsText = !empty($analysis['key_offerings'])
                ? implode(', ', array_slice($analysis['key_offerings'], 0, 3))
                : $keyOffering;

            $anglesText = '';
            if (!empty($analysis['suggested_campaign_angles'])) {
                $anglesText = "\n\n **Axes de campagne identifiés :**\n";
                foreach (array_slice($analysis['suggested_campaign_angles'], 0, 4) as $i => $angle) {
                    $num = $i + 1;
                    $anglesText .= "  {$num}. {$angle}\n";
                }
            }

            $uspText = '';
            if (!empty($analysis['unique_selling_proposition'])) {
                $uspText = "\n💡 **Avantage concurrentiel :** {$analysis['unique_selling_proposition']}";
            }

            return "🔍J'ai analysé votre site web pour **{$businessName}**.\n"
                . "📋 Secteur : " . ($analysis['activity_sector'] ?? 'Général') . "\n"
                . "⭐ Points forts détectés : {$offeringsText}."
                . $uspText
                . $anglesText
                . "\n👥 J'ai ciblé **{$count} contacts** ({$audienceLabel}).\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "📣 **Proposition de campagne #{$command->id}** (" . strtoupper($channel) . ") :\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
                . "{$generated['message']}\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "✅ Voulez-vous confirmer cette campagne ? (tapez /confirm {$command->id})";
        }

        // 2. Gestion des commandes de campagne directe (sans site web)
        if ($intent === 'prepare_campaign') {
            if (!$collaboratorUserId) {
                return 'Votre compte local n’est pas encore relié à un compte collaborateur. Reliez-le avant de demander vos contacts.';
            }

            if (($parsedCommand['channel'] ?? 'unknown') === 'unknown') {
                return "Sur quel canal souhaitez-vous envoyer cette campagne ? (SMS, Email ou WhatsApp) ?";
            }

            $audienceType = $parsedCommand['audience'] ?? 'all';
            $inactiveDays = $parsedCommand['inactive_days'] ?? null;
            $offerPercent = $parsedCommand['offer_percent'] ?? 10;
            $channel = $parsedCommand['channel'] ?? 'sms';

            $searchArgs = ['collaborator_user_id' => $collaboratorUserId];
            if ($audienceType === 'inactive' && $inactiveDays !== null) {
                $searchArgs['inactive_days'] = $inactiveDays;
                $audienceLabel = "clients inactifs depuis {$inactiveDays} jours";
            } else {
                $audienceLabel = "l'ensemble de vos clients";
            }

            $contacts = $this->tools->execute('search_contacts', $searchArgs);
            $items = $this->extractContactList($contacts);
            $count = count($items);

            $message = $this->tools->execute('generate_message', [
                'offer'    => "{$offerPercent}% de réduction",
                'audience' => $audienceLabel,
                'channel'  => $channel,
            ]);

            $command = AiCommand::create([
                'collaborator_user_id' => $collaboratorUserId,
                'intent'               => 'DRAFT_MESSAGE',
                'parameters'           => [
                    'offer'           => "{$offerPercent}% de réduction",
                    'audience'        => $audienceLabel,
                    'message'         => $message['message'],
                    'contact_ids'     => $this->contactIds($items),
                    'message_channel' => $channel,
                ],
                'status'               => 'PROPOSED',
                'estimated_recipients' => $count,
            ]);

            return "J'ai trouvé {$count} contacts ({$audienceLabel}). Proposition #{$command->id} :\n"
                . "« {$message['message']} »\n\n"
                . "Voulez-vous confirmer cette campagne ?";
        }

        // 3. Gestion de la recherche et liste de contacts
        $inactiveDays = $parsedCommand['inactive_days'] ?? null;
        $listContacts = $intent === 'list_contacts';

        if (preg_match('/clients? inactifs? depuis (\d+) jours/i', $userMessage, $matches)) {
            $inactiveDays = (int) $matches[1];
        }

        if ($inactiveDays !== null || $listContacts) {
            if (!$collaboratorUserId) {
                return 'Votre compte local n’est pas encore relié à un compte collaborateur. Reliez-le avant de demander vos contacts.';
            }

            $arguments = ['collaborator_user_id' => $collaboratorUserId];
            if ($inactiveDays !== null) {
                $arguments['inactive_days'] = $inactiveDays;
            }

            $contacts = $this->tools->execute('search_contacts', $arguments);
            $items = $this->extractContactList($contacts);
            $count = count($items);

            if (preg_match('/promotion de (\d+)\s*%/i', $userMessage, $offer)) {
                $offerPercent = (int) $offer[1];
                $message = $this->tools->execute('generate_message', [
                    'offer' => $offerPercent . '% de réduction',
                    'audience' => "clients inactifs depuis {$inactiveDays} jours",
                ]);

                $command = AiCommand::create([
                    'collaborator_user_id' => $collaboratorUserId,
                    'intent' => 'DRAFT_MESSAGE',
                    'parameters' => [
                        'offer' => $offerPercent . '% de réduction',
                        'audience' => "clients inactifs depuis {$inactiveDays} jours",
                        'message' => $message['message'],
                        'contact_ids' => $this->contactIds($items),
                        'message_channel' => $parsedCommand['channel'] ?? 'sms',
                    ],
                    'status' => 'PROPOSED',
                    'estimated_recipients' => $count,
                ]);

                return "J'ai trouvé {$count} contacts. Proposition #{$command->id} :\n{$message['message']}\n\nVoulez-vous confirmer cette campagne ?";
            }

            return $listContacts
                ? "J'ai trouvé {$count} contacts dans votre compte collaborateur."
                : "J'ai trouvé {$count} contacts inactifs depuis {$inactiveDays} jours.";
        }

        // 4. Conversation générale
        $systemPrompt = <<<PROMPT
Tu es TPE AI Assistant, l'assistant IA d'une plateforme de communication
et de marketing pour les TPE. Tu aides les commerçants et professionnels à analyser
leur site web, gérer leurs contacts et concevoir des campagnes marketing ciblées.
Réponds en français, de manière claire, engageante et concise.
PROMPT;

        return $this->llm->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ...$conversation,
            ['role' => 'user', 'content' => $userMessage],
        ]);
    }

    /**
     * Déballe la réponse /contacts de l'API collaborateur.
     *
     * @param mixed $response
     * @return array<int, mixed>
     */
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

    /**
     * Extrait les IDs de contacts.
     *
     * @param mixed $items
     * @return array<int, string>
     */
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