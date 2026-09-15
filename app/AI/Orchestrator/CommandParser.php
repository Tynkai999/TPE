<?php

namespace App\AI\Orchestrator;

use App\AI\LLM\LLMProvider;
use JsonException;

class CommandParser
{
    public function __construct(
        private readonly LLMProvider $llm,
    ) {}

    /**
     * @return array{
     *     intent: string,
     *     url?: string,
     *     audience?: string,
     *     inactive_days?: int,
     *     offer_percent?: int,
     *     offer_text?: string,
     *     channel?: string
     * }|null
     */
    /**
     * @param array<int, array{role: string, content: string}> $conversation
     */
    public function parse(string $message, array $conversation = []): ?array
    {
        $hasUrl = preg_match('/https?:\/\/[^\s<>"\'{}|\\^`]+/i', $message, $urlMatches);
        $extractedUrl = $hasUrl ? rtrim($urlMatches[0], '.,;') : null;

        // Si l'utilisateur fait référence au site mais n'a pas remis l'URL, on cherche dans l'historique
        if ($extractedUrl === null && !empty($conversation)) {
            // On cherche de la plus récente à la plus ancienne
            foreach (array_reverse($conversation) as $msg) {
                if ($msg['role'] === 'user' && preg_match('/https?:\/\/[^\s<>"\'{}|\\^`]+/i', $msg['content'], $m)) {
                    $extractedUrl = rtrim($m[0], '.,;');
                    break;
                }
            }
        }

        if ($extractedUrl === null && !preg_match('/contact|client|campagne|promotion|inactif|site|web|analyse|analyser/i', $message)) {
            return null;
        }

        $systemPrompt = <<<'PROMPT'
Tu es un analyseur d'intentions pour l'assistant marketing TPE Assistant.
Retourne UNIQUEMENT un JSON valide, sans balises markdown, correspondant à l'une de ces formes :

1. Analyse de site web avec proposition de campagne (UNIQUEMENT si une URL est présente dans le message ou l'historique) :
{"intent":"analyze_website","url":"<URL_TROUVEE>","audience":"all","channel":"unknown"}
ou si ciblage inactif :
{"intent":"analyze_website","url":"<URL_TROUVEE>","audience":"inactive","inactive_days":30,"channel":"unknown"}

2. Lister tous les contacts :
{"intent":"list_contacts"}

3. Rechercher des contacts spécifiques :
{"intent":"search_contacts","inactive_days":30}

4. Préparer une campagne (sans analyse de site) :
Pour tous les clients :
{"intent":"prepare_campaign","audience":"all","offer_percent":15,"channel":"unknown"}
ou pour les inactifs :
{"intent":"prepare_campaign","audience":"inactive","inactive_days":30,"offer_percent":15,"channel":"unknown"}

5. Conversation générale, poser une question ou demander un conseil (même si cela concerne un site web déjà analysé) :
{"intent":"chat"}

RÈGLES TRÈS IMPORTANTES :
- L'intention "analyze_website" et "prepare_campaign" servent EXCLUSIVEMENT à générer une campagne marketing (SMS/Email/WhatsApp).
- Si l'utilisateur pose une question (ex: "quelles améliorations...", "comment faire...", "qui es-tu ?"), choisis OBLIGATOIREMENT "chat", même s'il parle de son site web.
- "channel" DOIT être "sms", "email", ou "whatsapp" selon ce que demande l'utilisateur. Si l'utilisateur demande clairement une campagne mais ne précise pas le canal, mets OBLIGATOIREMENT "channel": "unknown".
- N'invente JAMAIS d'URL. Si aucune URL n'est dans le message de l'utilisateur ni dans l'historique, n'utilise JAMAIS l'intention "analyze_website" (utilise "prepare_campaign" à la place).
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        // On injecte les 4 derniers messages pour le contexte LLM (si besoin de faire des liens)
        $recentHistory = array_slice($conversation, -4);
        foreach ($recentHistory as $msg) {
            // On tronque légèrement le contenu du bot s'il est trop long pour ne pas polluer l'extracteur JSON
            $content = strlen($msg['content']) > 300 ? substr($msg['content'], 0, 300) . '...' : $msg['content'];
            $messages[] = ['role' => $msg['role'], 'content' => $content];
        }
        
        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $responseArr = $this->llm->chat($messages);
            $response = $responseArr['content'] ?? '';
        } catch (\Throwable) {
            $response = '';
        }

        $response = trim(preg_replace('/^```(?:json)?|```$/m', '', $response) ?? $response);

        $parsed = null;
        try {
            $parsed = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Si le LLM n'a pas répondu en JSON : extraction déterministe de secours
            if ($hasUrl || ($extractedUrl !== null && preg_match('/campagne|promotion|offre/i', $message))) {
                $isInactive = preg_match('/inactif/i', $message);
                $days = preg_match('/(\d+)\s*jours?/i', $message, $d) ? (int) $d[1] : 30;
                
                $channel = preg_match('/email|mail/i', $message) ? 'email' : (preg_match('/whatsapp/i', $message) ? 'whatsapp' : (preg_match('/sms/i', $message) ? 'sms' : 'unknown'));

                return [
                    'intent'        => 'analyze_website',
                    'url'           => $extractedUrl,
                    'audience'      => $isInactive ? 'inactive' : 'all',
                    'inactive_days' => $isInactive ? $days : null,
                    'channel'       => $channel,
                ];
            }

            if (preg_match('/promotion|campagne|offre|réduction/i', $message) || preg_match('/(\d+)\s*%/i', $message)) {
                $isInactive = preg_match('/inactif/i', $message);
                $days = preg_match('/(\d+)\s*jours?/i', $message, $d) ? (int) $d[1] : 30;
                $percent = preg_match('/(\d+)\s*%/i', $message, $p) ? (int) $p[1] : 10;
                $channel = preg_match('/email|mail/i', $message) ? 'email' : (preg_match('/whatsapp/i', $message) ? 'whatsapp' : (preg_match('/sms/i', $message) ? 'sms' : 'unknown'));

                return [
                    'intent'        => 'prepare_campaign',
                    'audience'      => $isInactive ? 'inactive' : 'all',
                    'inactive_days' => $isInactive ? $days : null,
                    'offer_percent' => $percent,
                    'channel'       => $channel,
                ];
            }

            if (preg_match('/liste.*contact|mes contacts/i', $message)) {
                return ['intent' => 'list_contacts'];
            }

            if (preg_match('/inactifs? depuis (\d+) jours/i', $message, $m)) {
                return [
                    'intent'        => 'search_contacts',
                    'inactive_days' => (int) $m[1],
                ];
            }

            return null;
        }

        if (!is_array($parsed) || !isset($parsed['intent'])) {
            return null;
        }

        $intent = $parsed['intent'];
        if (!in_array($intent, ['list_contacts', 'search_contacts', 'prepare_campaign', 'analyze_website'], true)) {
            // Si une URL était présente dans le MESSAGE COURANT mais l'intention a été classée "chat"
            if ($hasUrl) {
                $intent = 'analyze_website';
            } else {
                return null;
            }
        }

        $channel = isset($parsed['channel']) && in_array($parsed['channel'], ['sms', 'email', 'whatsapp', 'unknown'], true)
            ? $parsed['channel']
            : 'unknown';

        // 1. Intention : analyze_website
        if ($intent === 'analyze_website') {
            $url = $parsed['url'] ?? $extractedUrl;
            
            // Sécurité anti-hallucination : Si l'IA invente une URL, on rétrograde en création de campagne simple
            if (!is_string($url) || $url === '' || str_contains($url, '<URL_TROUVEE') || str_contains($url, 'exemple.fr')) {
                $intent = 'prepare_campaign';
            } else {
                $audience = ($parsed['audience'] ?? '') === 'inactive' || preg_match('/inactif/i', $message)
                    ? 'inactive'
                    : 'all';

                $inactiveDays = null;
                if ($audience === 'inactive') {
                    $inactiveDays = isset($parsed['inactive_days']) && is_numeric($parsed['inactive_days'])
                        ? max(1, (int) $parsed['inactive_days'])
                        : (preg_match('/(\d+)\s*jours?/i', $message, $m) ? (int) $m[1] : 30);
                }

                return [
                    'intent'        => 'analyze_website',
                    'url'           => $url,
                    'audience'      => $audience,
                    'inactive_days' => $inactiveDays,
                    'channel'       => $channel,
                ];
            }
        }

        // 2. Intention : list_contacts
        if ($intent === 'list_contacts') {
            return ['intent' => 'list_contacts'];
        }

        // 3. Intention : search_contacts
        if ($intent === 'search_contacts') {
            $inactiveDays = isset($parsed['inactive_days']) && is_numeric($parsed['inactive_days'])
                ? max(1, (int) $parsed['inactive_days'])
                : 30;

            return [
                'intent'        => 'search_contacts',
                'inactive_days' => $inactiveDays,
            ];
        }

        // 4. Intention : prepare_campaign
        if ($intent === 'prepare_campaign') {
            $audience = ($parsed['audience'] ?? '') === 'inactive' || preg_match('/inactif/i', $message)
                ? 'inactive'
                : 'all';

            $inactiveDays = null;
            if ($audience === 'inactive') {
                $inactiveDays = isset($parsed['inactive_days']) && is_numeric($parsed['inactive_days'])
                    ? max(1, (int) $parsed['inactive_days'])
                    : (preg_match('/(\d+)\s*jours?/i', $message, $m) ? (int) $m[1] : 30);
            }

            $offerPercent = isset($parsed['offer_percent']) && is_numeric($parsed['offer_percent'])
                ? max(1, (int) $parsed['offer_percent'])
                : (preg_match('/(\d+)\s*%/i', $message, $p) ? (int) $p[1] : null);

            return [
                'intent'        => 'prepare_campaign',
                'audience'      => $audience,
                'inactive_days' => $inactiveDays,
                'offer_percent' => $offerPercent,
                'channel'       => $channel,
            ];
        }

        return null;
    }
}
