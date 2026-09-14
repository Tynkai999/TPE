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
FORMAT : Campagne Email Ultra-Professionnelle et Persuasive.
Tu dois rédiger une newsletter/email marketing riche, structuré et très qualitatif (formaté en Markdown). Utilise le framework de copywriting AIDA (Attention, Intérêt, Désir, Action) ou PAS (Problème, Agitation, Solution).

Structure stricte à respecter :

**OBJET :** [Propose 3 options d'objets ultra-cliquables (pour A/B testing) avec emojis]
**PRÉ-HEADER :** [Texte de teasing impactant, 80-100 caractères]

---
*[Suggestion visuelle : Décris une image principale pertinente à insérer ici (ex: Photo chaleureuse, visuel produit, etc.)]*

# [Grand titre accrocheur (H1)]

*Bonjour [Prénom],*

**[L'Accroche / Le Problème]** 
Commence par une histoire courte, une question forte ou le constat d'une situation que vit le client. Capte immédiatement l'attention. (3-4 phrases)

**[La Solution / La Proposition de Valeur]**
Présente le produit, l'offre ou la nouveauté de manière séduisante. Montre que c'est la solution évidente ou l'opportunité à ne pas manquer. 

**[Pourquoi vous allez adorer (Les Bénéfices)]**
* ✅ **[Bénéfice 1]** : [Explication de l'impact positif concret]
* ✅ **[Bénéfice 2]** : [Explication de l'impact positif concret]
* ✅ **[Bénéfice 3]** : [Explication de l'impact positif concret]

*[Suggestion visuelle : Image secondaire ou bouton]*

**[Preuve sociale ou Réassurance]**
Ajoute un élément de confiance : témoignage fictif mais ultra-réaliste, garantie, mention de l'expertise de l'entreprise, ou chiffre clé.

**[Appel à l'action / Bouton]**
👉 **[ TEXTE DU BOUTON CTA - Ex: Découvrir l'offre, Réserver ma place ]** 👈

*Signature chaleureuse,*
**L'équipe [Nom de l'entreprise]**
[Secteur / Coordonnées]

---
**P.S.** : [Le post-scriptum est indispensable. Ajoute un P.S. créant un sentiment d'urgence ou rappelant le bénéfice principal de manière amicale et directe.]

L'email doit être aéré, utiliser du gras pour les mots importants, et faire au moins 300 à 450 mots. C'est une vraie campagne de copywriting haut de gamme.
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
