# Carbure : le réel face au prévu

[Carbure](https://github.com/ltoinel/Carbure) est une application libre et auto-hébergée de suivi des dépenses :
elle récupère vos opérations bancaires et les range par catégories. Reliée à **votre** instance Carbure,
Patrimeter lit les **dépenses réelles** de chaque mois et les compare au [budget](budget.md) **prévu**.

## Relier votre instance

1. Dans Carbure, l'administrateur active le **serveur MCP** (il est désactivé par défaut).
2. Dans Carbure, créez un **jeton d'API** : Réglages, Jetons d'API, Créer. Une durée limitée (90 jours, un an) est
   préférable. Le jeton commence par `cbt_` ; il ne s'affiche qu'une fois.
3. Dans Patrimeter, [Mon compte](securite.md), onglet **Carbure** : l'adresse de votre instance (par exemple
   `https://carbure.maison.lan`) et le jeton, puis **Relier Carbure**. L'adresse du service MCP que montre Carbure
   (`https://carbure.maison.lan/api/mcp`) convient aussi.

Patrimeter lit alors vos catégories de dépenses : la page indique leur nombre. Chaque utilisateur relie sa propre
instance ; le jeton est chiffré avec votre clé et n'est jamais réaffiché (seuls ses quatre derniers caractères).
Pour changer d'adresse sans changer de jeton, laissez le champ du jeton vide.

## Rapprocher les catégories

Chaque catégorie de dépenses de premier niveau de Carbure (ses sous-catégories comprises) compte pour **une
catégorie du budget**, ou est **ignorée**. Les catégories de même nom sont rapprochées d'office ; les autres se
choisissent dans **Correspondance des catégories**. Les catégories de revenus de Carbure ne sont pas reprises.

## Le prévu et le réel

La page du budget ajoute la section **Prévu et réel** : pour chaque catégorie rapprochée, le montant prévu, la
dépense réelle du mois dans Carbure et l'écart (réel moins prévu : positif, la dépense dépasse le budget), avec deux
barres. Les dépenses des catégories sans correspondance sont signalées, avec un lien pour les rapprocher.

Si Carbure ne répond pas, refuse le jeton ou n'a pas activé son serveur MCP, le budget l'indique et s'affiche
quand même. Le message distingue un jeton refusé (à recréer dans Carbure) d'une adresse sans service MCP de Carbure
(adresse erronée, ou serveur MCP désactivé).

## Sécurité

- Patrimeter n'appelle que l'adresse `…/api/mcp` de votre instance, en lecture seule, sans suivre de redirection,
  avec un délai et une taille de réponse limités ; il ne renvoie jamais ce qu'il y lit.
- Les appels sont limités par utilisateur (une rafale de 30, puis un toutes les 10 secondes).
- L'administrateur de Patrimeter peut restreindre les instances autorisées (`CARBURE_ALLOWED_HOSTS`, voir
  [l'installation](../exploitation/installation.md#3-configurer)).
- **Supprimer le lien** efface l'adresse, le jeton et la correspondance de Patrimeter ; révoquez aussi le jeton dans
  Carbure.
