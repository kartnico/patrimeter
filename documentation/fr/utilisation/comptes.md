# Comptes et crédits

Un **compte** est ce que vous possédez (compte courant, livret, assurance-vie, PEA…), un **crédit** ce que
vous devez (prêt immobilier, prêt à la consommation…). Onglet **Comptes** de [Mon compte](securite.md) (menu du
compte, **Mon profil**). Les **biens immobiliers** ont leur propre onglet, **Biens immobiliers** : ils se créent là,
avec votre part et leurs crédits ([biens immobiliers](biens.md)). Pour créer de nombreux comptes, biens et crédits
d'un coup, depuis un tableur : [import CSV](import-csv.md).

La liste présente vos comptes comme la [saisie du mois](saisie.md) : un tableau par niveau de la pyramide, avec
pour chacun son type, son statut (**saisi** ce mois-ci, ou **reporté** : dernier solde connu) et son solde du mois.
Un crédit porte la mention **dû** et son capital restant dû précédé de « − ». Le nom d'un compte ouvre sa fiche.

![Liste des comptes](../captures/comptes-ordinateur.png)

Modifier la date d’ouverture ne peut pas placer un relevé existant avant le premier mois d’activité du compte.

## Créer un compte

**+ Nouveau compte**, puis :

| Champ | Rôle |
|---|---|
| Nom | Comme vous l'appelez (« Livret A de Marie ») |
| Type | Types prédéfinis ; il propose le niveau et la nature. Au niveau Exotique, des biens tangibles : or, argent et autres métaux précieux, bijoux, montres, œuvres d'art et objets de collection, vins et spiritueux, voiture, moto (saisissez leur valeur de revente estimée). Un clic sur le champ (ou sur sa flèche) affiche toute la liste ; tapez pour ne garder que les types qui contiennent le texte (« livret », « pea », « credit »…), sans tenir compte des majuscules ni des accents ; flèches et Entrée pour choisir au clavier |
| Niveau de la pyramide | Résidence principale, épargne de précaution, fonds euros, actions, immobilier, exotique |
| Nature | **Actif** (compte, placement, bien) ou **passif** (crédit, dette) |
| Date d'ouverture | Le compte compte à partir de ce mois |
| Solde actuel | Facultatif : relevé du mois en cours |

![Nouveau compte](../captures/nouveau-compte-ordinateur.png)

Pour un crédit, saisissez le **capital restant dû**, en positif : Patrimeter le soustrait du niveau.

## Relevés

La fiche d'un compte rappelle son type, son niveau, sa nature et sa date d'ouverture, puis liste ses relevés mois
par mois ; **Ajouter / corriger le relevé** saisit ou corrige
un mois passé. Le mois se choisit dans le calendrier, comme en haut des pages. La saisie
courante se fait plutôt dans la [saisie du mois](saisie.md), pour tous les comptes
à la fois.

### Lire un relevé bancaire

Plutôt que de recopier un solde, **Ou lire le solde d'un relevé bancaire** prend le fichier que votre banque exporte
pour ce compte, au format **OFX** (ou QFX) ou **CAMT.053** (ISO 20022) : Patrimeter en lit le solde de fin de
période et sa date, puis remplit le mois et le montant du formulaire. Vérifiez-les, puis **Ajouter / corriger le
relevé** : rien n'est enregistré avant. Pour un crédit, le solde négatif du relevé devient le capital restant dû.

Les fichiers **QIF** et **CSV** des banques donnent les opérations, sans le solde : exportez plutôt un relevé OFX ou
CAMT.053 (la plupart des banques les proposent dans l'espace « Télécharger mes opérations »). Un fichier en devise
étrangère, ou qui contient plusieurs comptes, est refusé avec un message qui dit quoi faire.

## Clôturer, réactiver, supprimer

- **Archiver** avec une date de clôture : le compte ne compte plus à partir du mois qui suit ; son
  historique reste.
- **Réactiver** un compte archivé.
- **Supprimer définitivement** : le compte et tous ses relevés disparaissent, y compris de l'historique.
