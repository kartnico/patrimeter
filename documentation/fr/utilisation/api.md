# API et assistants (MCP)

Patrimeter offre une **API REST** et un **service MCP** (pour un assistant comme Claude) avec la même
authentification que le site :

- **jeton personnel**, créé dans Mon compte, onglet « Agents IA » (lecture seule ou lecture et écriture,
  durée limitée) ;
- ou **accès accordé à une application**, après connexion et consentement dans le navigateur (OAuth 2.1).

Chaque accès se retire depuis le même onglet, qui donne aussi les adresses du service MCP et de l'API à indiquer
à l'agent IA (menu du compte, **Agents IA**), chacune avec un bouton **Copier** qui la met dans le presse-papiers. Un
assistant qui appelle ces outils transmet vos données à
son fournisseur : le consentement porte aussi sur cela.

Le débit est limité par jeton : 120 requêtes d'affilée, puis une par seconde.

L'API et le service MCP sont **désactivés par défaut** : l'administrateur de l'instance les active, chacun, par
les variables `API_ENABLED` et `MCP_ENABLED` ([API et MCP](../exploitation/api.md)). Tant qu'aucun des deux n'est
activé, l'onglet « Agents IA » n'apparaît pas dans Mon compte, ni dans le menu du compte.

Référence complète (adresses, outils MCP, exemples) : [API et MCP](../exploitation/api.md). Chaque opération de l'API, ses
paramètres et ses réponses : [référence de l'API](../exploitation/api-reference.md), générée depuis la description
OpenAPI ([openapi.yaml](../exploitation/openapi.yaml)).
