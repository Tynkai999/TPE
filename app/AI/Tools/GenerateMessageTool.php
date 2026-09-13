<?php

namespace App\AI\Tools;

use App\AI\LLM\LLMProvider;
use InvalidArgumentException;

class GenerateMessageTool implements Tool
{
    public function __construct(
        private readonly LLMProvider $llm,
    ) {}

    public function name(): string
    {
        return 'generate_message';
    }

    public function description(): string
    {
        return 'Génère un brouillon complet de campagne marketing professionnelle à soumettre à confirmation.';
    }

    /**
     * @param array<string, mixed> $arguments
     *   - offer (string, obligatoire si key_offering non fourni)
     *   - audience (string, obligatoire)
     *   - business_name (string, optionnel)
     *   - tone (string, optionnel)
     *   - channel (string, optionnel: 'sms', 'email', 'whatsapp')
     *   - key_offering (string, optionnel)
     *   - activity_sector (string, optionnel)
     *   - key_offerings_list (array, optionnel — liste complète de produits/services)
     * @return array{message: string}
     */
    public function execute(array $arguments): array
    {
        $offer = $arguments['offer'] ?? null;
        $audience = $arguments['audience'] ?? null;
        $businessName = $arguments['business_name'] ?? null;
        $tone = $arguments['tone'] ?? 'chaleureux et professionnel';
        $channel = $arguments['channel'] ?? 'sms';
        $keyOffering = $arguments['key_offering'] ?? null;
        $activitySector = $arguments['activity_sector'] ?? null;
        $allOfferings = $arguments['key_offerings_list'] ?? [];

        if ((!is_string($offer) || $offer === '') && (!is_string($keyOffering) || $keyOffering === '')) {
            throw new InvalidArgumentException('Une offre ou une spécialité/produit phare est obligatoire.');
        }

        if (!is_string($audience) || $audience === '') {
            $audience = 'tous nos clients';
        }

        $effectiveOffer = $offer ?: "Mise en avant de : {$keyOffering}";

        $systemPrompt = $this->buildSystemPrompt($channel);
        $userPrompt = $this->buildUserPrompt(
            channel: $channel,
            audience: $audience,
            effectiveOffer: $effectiveOffer,
            businessName: $businessName,
            tone: $tone,
            keyOffering: $keyOffering,
            activitySector: $activitySector,
            allOfferings: $allOfferings,
        );

        $message = $this->llm->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ]);

        return ['message' => trim($message)];
    }

    /**
     * Construit le system prompt adapté au canal de diffusion.
     */
    private function buildSystemPrompt(string $channel): string
    {
        $baseIdentity = <<<'PROMPT'
Tu es un directeur artistique et rédacteur publicitaire senior dans une agence de communication digitale spécialisée en marketing direct pour les TPE/PME.
Tu crées des campagnes marketing PROFESSIONNELLES, COMPLÈTES et PRÊTES À L'ENVOI — pas des brouillons, pas des ébauches.

RÈGLES ABSOLUES :
1. Écris en français impeccable, avec un style publicitaire percutant et engageant.
2. Utilise des techniques de copywriting éprouvées : accroche émotionnelle, bénéfice client clair, preuve sociale si possible, appel à l'action (CTA) fort et urgent.
3. N'invente JAMAIS de prix, de pourcentages de réduction ou de conditions commerciales qui ne sont pas explicitement fournis dans le brief.
4. Retourne UNIQUEMENT le contenu de la campagne, sans commentaire, sans explication, sans guillemets englobants.
PROMPT;

        return match ($channel) {
            'sms' => $baseIdentity . "\n\n" . <<<'SMS'
FORMAT : SMS Marketing (160–320 caractères max).
Structure obligatoire :
- Accroche percutante (prénom si possible, sinon interpellation directe)
- Bénéfice client en une phrase
- CTA clair avec verbe d'action
- Signature de l'entreprise

Exemple de qualité attendue :
« 🌟 [Prénom], votre expertise mérite d'être vue ! Boostez votre visibilité en ligne avec notre nouvelle offre sur mesure. Réservez votre audit gratuit → [lien]. — L'équipe [Entreprise] »
SMS,

            'email' => $baseIdentity . "\n\n" . <<<'EMAIL'
FORMAT : Email Marketing Complet.
Structure obligatoire avec séparateurs clairs :

**OBJET :** [Ligne d'objet accrocheuse, 50-70 caractères, avec emoji si pertinent]

**PRÉ-HEADER :** [Texte de pré-visualisation, 80-100 caractères]

---

**CORPS DE L'EMAIL :**

1. **Accroche** (2-3 phrases) — Interpelle le destinataire, crée une connexion émotionnelle ou pose une question rhétorique liée à son besoin.

2. **Proposition de valeur** (3-5 phrases) — Présente l'offre ou le service mis en avant. Utilise des bullet points (•) pour lister les bénéfices concrets. Mets en gras les mots-clés importants.

3. **Preuve / Crédibilité** (1-2 phrases) — Mentionne l'expertise, l'ancienneté, un chiffre clé ou une spécialité distinctive de l'entreprise.

4. **Appel à l'action (CTA)** — Un bouton ou lien clair avec un verbe d'action : « Découvrir maintenant », « Réserver mon créneau », « Profiter de l'offre ».

5. **Signature professionnelle** — Nom de l'entreprise, secteur, coordonnées suggérées.

Le résultat doit faire entre 150 et 300 mots — une vraie campagne email prête à envoyer.
EMAIL,

            'whatsapp' => $baseIdentity . "\n\n" . <<<'WHATSAPP'
FORMAT : Message WhatsApp Business (500-800 caractères).
Structure obligatoire :

1. **Salutation chaleureuse** avec emoji contextuel
2. **Message principal** (3-4 phrases) — Présentation de l'offre ou du service de manière conversationnelle mais professionnelle. Utilise des emojis avec parcimonie (2-3 max).
3. **Bénéfices clés** — Liste courte avec emojis (✅, 🎯, 💡)
4. **CTA conversationnel** — « Répondez OUI pour en savoir plus », « Cliquez ici pour réserver », etc.
5. **Signature** de l'entreprise

Ton : conversationnel mais professionnel, comme un message personnalisé d'un conseiller de confiance.
WHATSAPP,

            default => $baseIdentity . "\n\n" . <<<'DEFAULT'
FORMAT : Message Marketing Complet (200-400 mots).
Produis une campagne complète avec accroche, corps développé, bénéfices listés, CTA et signature.
DEFAULT,
        };
    }

    /**
     * Construit le brief utilisateur détaillé pour le LLM.
     */
    private function buildUserPrompt(
        string $channel,
        string $audience,
        string $effectiveOffer,
        ?string $businessName,
        string $tone,
        ?string $keyOffering,
        ?string $activitySector,
        array $allOfferings,
    ): string {
        $lines = ["=== BRIEF DE CAMPAGNE ===\n"];

        if (is_string($businessName) && $businessName !== '') {
            $lines[] = "🏢 Entreprise : {$businessName}";
        }
        if (is_string($activitySector) && $activitySector !== '') {
            $lines[] = "📋 Secteur d'activité : {$activitySector}";
        }
        $lines[] = "📣 Canal de diffusion : " . strtoupper($channel);
        $lines[] = "👥 Audience ciblée : {$audience}";
        $lines[] = "🎯 Offre / Thématique principale : {$effectiveOffer}";

        if (is_string($keyOffering) && $keyOffering !== '') {
            $lines[] = "⭐ Produit/Service phare à mettre en avant : {$keyOffering}";
        }

        if (!empty($allOfferings) && is_array($allOfferings)) {
            $offeringsStr = implode(', ', array_slice($allOfferings, 0, 5));
            $lines[] = "📦 Autres produits/services de l'entreprise : {$offeringsStr}";
        }

        $lines[] = "🎨 Tonalité souhaitée : {$tone}";
        $lines[] = '';
        $lines[] = "Rédige maintenant la campagne complète et professionnelle, prête à l'envoi. Ne fais aucun commentaire, ne donne aucune explication — uniquement le contenu de la campagne.";

        return implode("\n", $lines);
    }
}
