# Compte famille

Un **compte famille** réunit le patrimoine et le budget d'un couple, chacun gardant **son propre compte** : chacun
saisit ses données de son côté, les exporte chaque mois, et le compte famille les importe. Les pages
Patrimoine (avec son historique) et Budget, et le rapport, montrent alors **la famille**, ou **chacun**.

![Membres de la famille](../captures/famille-ordinateur.png)

## Mettre en place

1. Chacun crée son compte Patrimeter et y saisit ses comptes, ses soldes et, s'il le souhaite, son
   [budget](budget.md).
2. Créez un **troisième compte**, pour la famille (une autre adresse e-mail).
3. Dans ce compte, ouvrez **Membres de la famille** (lien de la page Patrimoine de bienvenue, ou de la page
   [Vos données](donnees.md)), puis **Ajouter un membre** : son nom et son fichier d'export.

Un compte famille réunit **deux membres au plus**.

## Ce qui est commun : chacun sa part

La famille **additionne** les données de ses membres : pour ne rien compter deux fois, chacun saisit **sa part** de
ce qui est commun.

| Commun | Ce que saisit chacun |
|---|---|
| Compte joint | la moitié de son solde (ou sa part) |
| Maison achetée à deux | le bien avec sa **quote-part** (50 %…) et son crédit lié, au prorata (voir [Biens immobiliers](biens.md)) |
| Loyer, courses, factures | sa part de chaque dépense, dans son budget |

Le compte famille peut aussi avoir **ses propres comptes**, par exemple ceux des enfants : ils comptent dans la
famille.

## Chaque mois

1. Chacun télécharge ses données : **Vos données** → **Télécharger mes données**.
2. Dans le compte famille, **Membres de la famille** → **Mettre à jour** pour chaque membre, avec son nouveau
   fichier : il **remplace** le précédent import.

La page indique pour chacun la date du dernier import et celle du fichier ; un fichier d'un mois précédent est
signalé (**Attention**). **Retirer** un membre supprime ses données importées du compte famille, jamais de son
propre compte.

## Les vues de la famille

Dès qu'un membre est importé, la page Patrimoine, le rapport à partager et le budget proposent un
choix en tête de page :

- **Toute la famille** (par défaut) : les comptes des membres et ceux du compte famille ; le budget réunit ceux des
  membres ;
- **un membre** : ses seules données, telles qu'il les voit dans son compte ;
- **Ce compte** : les données propres au compte famille, s'il en a.

Le choix est retenu d'une page à l'autre. Dans la famille :

- les comptes importés portent le nom de leur membre (« Livret A · Alex ») et ne se modifient pas ;
- le **budget** est en lecture seule : chaque membre devient un adulte, avec ses revenus et toutes ses catégories ;
  il n'y a pas de compte commun à partager, chacun ayant saisi sa part. Les enfants de même prénom sont réunis, et
  leur coût additionne les deux budgets ; la cible de précaution est la plus haute des deux ;
- les [recommandations](tableau-de-bord.md#recommandations) de la page Patrimoine rapportent l'épargne de précaution de
  la famille aux dépenses de la famille.

## Confidentialité

- Les données importées sont **chiffrées avec la clé du compte famille**, comme toutes les données patrimoniales :
  l'administrateur du serveur ne les lit pas.
- Le fichier d'export n'est pas chiffré : supprimez-le une fois importé.
- L'export du compte famille ne contient que ses **propres** données, pas celles de ses membres.
