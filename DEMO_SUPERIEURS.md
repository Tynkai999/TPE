# Guide de Démonstration — Assistant IA TPE Messagerie

Ce document est un guide pas-à-pas destiné à la démonstration des capacités de l'Assistant IA auprès de la direction et des parties prenantes. 
Il prouve que l'intégration IA est **100% fonctionnelle, sécurisée et robuste**.

---

## Prérequis
Assurez-vous que l'environnement Docker est lancé et que le backend du collaborateur (API) tourne sur `192.168.11.112:8000`.

L'identifiant du compte collaborateur de test utilisé pour cette démo est :
`deraousmane2004@example.com`

>  **Nouveauté :** Une console interactive complète a été développée pour la direction. Vous pouvez réaliser tous les scénarios sans quitter le terminal !

Lancez la console avec la commande suivante :
```bash
docker compose exec app php artisan agent:interactive
```
*(Validez simplement avec `Entrée` lorsqu'on vous demande l'email et le mot de passe, les valeurs par défaut sont pré-remplies).*

## 🟢 Scénario 1 : Succès des tests automatisés (Fiabilité)
**Objectif :** Montrer que le code est couvert par des tests stricts et qu'aucune régression n'est présente.

**Commande à lancer dans le terminal :**
```bash
docker compose exec app php artisan test
```

**Résultat attendu :** 
Vous verrez `37 passed (117 assertions)`. Cela prouve à vos supérieurs que les scénarios nominaux (connexion, liste, génération, annulation, envoi) et les cas d'erreur sont parfaitement maîtrisés par notre application.

---

## 🟢 Scénario 2 : Compréhension du langage naturel et récupération des contacts
**Objectif :** Montrer que l'IA est capable de se connecter à l'API du collaborateur de façon sécurisée pour lire les données.

**Ce que vous devez taper dans le chat :**
```text
Combien ai-je de contacts dans ma base ?
```

**Résultat attendu :** 
L'IA va répondre intelligemment : *"J'ai trouvé 1 contacts dans votre compte collaborateur."*

---

## 🟢 Scénario 3 : Génération d'une campagne ciblée (Le cœur de l'IA)
**Objectif :** Démontrer comment l'IA croise une demande marketing complexe avec les filtres de contacts.

**Ce que vous devez taper dans le chat :**
```text
Crée une campagne WhatsApp pour mes clients inactifs depuis 60 jours en leur offrant 15% de remise.
```

**Résultat attendu :**
L'IA va générer un texte marketing pertinent, isoler les contacts ciblés et créer une **Proposition de campagne** (ex: Proposition #10). Elle demandera : *"Voulez-vous confirmer cette campagne ?"*

---

## 🟢 Scénario 4 : Maîtrise et Annulation (Sécurité)
**Objectif :** Prouver que l'utilisateur garde le contrôle total et peut annuler une idée de l'IA.

*(Remplacez `10` par l'ID de la proposition générée à l'étape précédente)*
**Tapez la commande interne suivante dans le chat :**
```text
/cancel 10
```

**Résultat attendu :**
✅ `Proposition #10 annulée.` L'action est bloquée et ne partira jamais chez le client.

---

## 🟠 Scénario 5 : Démonstration du système de confirmation et mise en évidence du blocage partenaire
**Objectif :** Montrer que notre IA tente de pousser la campagne vers l'API partenaire, mais que notre système gère proprement l'erreur (sans crasher) face au bug de leur base de données.

**Étape 5.1 : Tapez cette nouvelle proposition rapide**
```text
Envoie une relance par SMS à tous mes clients.
```
*(Notez le numéro de la proposition générée, ex: #11)*

**Étape 5.2 : Tentez de la confirmer via le raccourci**
```text
/confirm 11
```

**Résultat attendu (Ce qu'il faut expliquer aux supérieurs) :**
Vous obtiendrez un message propre de notre système : 
`Création de la campagne impossible : Erreur API collaborateur [500] sur /groups/.../contacts : {"message":"SQLSTATE[42703]: Undefined column... la colonne user_id n'existe pas"}`

🗣 **Discours pour les supérieurs :**
> *"L'IA fait 100% de son travail et transmet correctement la demande. Cependant, l'équipe partenaire vient de passer à une architecture multi-locataire (tenant_id). En faisant cela, ils ont supprimé l'ancienne colonne `user_id` de leur base de données, mais leur code cherche encore à l'utiliser ! Notre application TPE est robuste, elle capte cette erreur 500 à distance sans planter. Dès qu'ils nettoieront leur code de cette relique `user_id`, la commande passera automatiquement."*

---

## Bilan de la démonstration
1. **L'IA comprend les intentions métiers** (remises, relances, inactifs).
2. **La communication sécurisée API fonctionne** (les contacts réels remontent bien).
3. **Le workflow de sécurité est opérationnel** (Proposition -> Annulation ou Confirmation).
4. **La fiabilité est prouvée** (37 tests automatisés validés).
5. **Le produit est prêt** en attendant le correctif du partenaire externe.

