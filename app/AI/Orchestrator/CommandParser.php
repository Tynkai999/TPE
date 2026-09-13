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
    public function parse(string $message): ?array
    {
        $hasUrl = preg_match('/https?:\/\/[^\s<>"\'{}|\\^`]+/i', $message, $urlMatches);
        $extractedUrl = $hasUrl ? rtrim($urlMatches[0], '.,;') : null;

        if (!$hasUrl && !preg_match('/contact|client|campagne|promotion|inactif|site|web|analyse|analyser/i', $message)) {
            return null;
        }

        $systemPrompt = <<<'PROMPT'
Tu es un analyseur d'intentions pour l'assistant marketing TPE Assistant.
Retourne UNIQUEMENT un JSON valide, sans balises markdown, correspondant à l'une de ces formes :

1. Analyse de site web avec proposition de campagne :
{"intent":"analyze_website","url":"https://exemple.fr","audience":"all","channel":"sms"}
ou si ciblage inactif :
{"intent":"analyze_website","url":"https://exemple.fr","audience":"inactive","inactive_days":30,"channel":"sms"}

2. Lister tous les contacts :
{"intent":"list_contacts"}

3. Rechercher des contacts spécifiques :
{"intent":"search_contacts","inactive_days":30}

4. Préparer une campagne (sans site web) :
Pour tous les clients :
{"intent":"prepare_campaign","audience":"all","offer_percent":15,"channel":"sms"}
ou pour les inactifs :
{"intent":"prepare_campaign","audience":"inactive","inactive_days":30,"offer_percent":15,"channel":"sms"}

5. Conversation générale :
{"intent":"chat"}
PROMPT;

        try {
            $response = $this->llm->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message],
            ]);
        } catch (\Throwable) {
            $response = '';
        }

        $response = trim(preg_replace('/^```(?:json)?|```$/m', '', $response) ?? $response);

        $parsed = null;
        try {
            $parsed = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Si le LLM n'a pas répondu en JSON : extraction déterministe de secours
            if ($extractedUrl !== null) {
                $isInactive = preg_match('/inactif/i', $message);
                $days = preg_match('/(\d+)\s*jours?/i', $message, $d) ? (int) $d[1] : 30;

                return [
                    'intent'        => 'analyze_website',
                    'url'           => $extractedUrl,
                    'audience'      => $isInactive ? 'inactive' : 'all',
                    'inactive_days' => $isInactive ? $days : null,
                    'channel'       => 'sms',
                ];
            }

            if (preg_match('/promotion|campagne|offre|réduction/i', $message) || preg_match('/(\d+)\s*%/i', $message)) {
                $isInactive = preg_match('/inactif/i', $message);
                $days = preg_match('/(\d+)\s*jours?/i', $message, $d) ? (int) $d[1] : 30;
                $percent = preg_match('/(\d+)\s*%/i', $message, $p) ? (int) $p[1] : 10;
                $channel = preg_match('/email|mail/i', $message) ? 'email' : (preg_match('/whatsapp/i', $message) ? 'whatsapp' : 'sms');

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
            // Si une URL était présente dans le message mais l'intention a été classée "chat"
            if ($extractedUrl !== null) {
                $intent = 'analyze_website';
            } else {
                return null;
            }
        }

        $channel = isset($parsed['channel']) && in_array($parsed['channel'], ['sms', 'email', 'whatsapp'], true)
            ? $parsed['channel']
            : 'sms';

        // 1. Intention : analyze_website
        if ($intent === 'analyze_website') {
            $url = $parsed['url'] ?? $extractedUrl;
            if (!is_string($url) || $url === '') {
                return null;
            }

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
