# Objectifs d'épargne

Menu **Objectifs** de la barre principale, entre Budget et Saisie du mois : un montant à atteindre à une date, et
chaque mois, Patrimeter vous dit si vous êtes sur la bonne voie. Rien de plus à saisir : le suivi se calcule à partir de
vos soldes mensuels.

![Objectifs d'épargne](../captures/objectifs-ordinateur.png)

## Fixer un objectif

Les objectifs se créent et se modifient dans **Saisie du mois**, onglet **Objectifs** : **Nouvel objectif**, ou le
nom d'un objectif pour le modifier ou le supprimer.

![Saisie des objectifs](../captures/saisie-objectifs-ordinateur.png)

**Nouvel objectif** :

- **Nom** : « Apport pour la maison », « Épargne de précaution »… ;
- **Cible** (liste) : **un montant**, ou **des mois de dépenses du budget** (par exemple 6 mois pour une épargne de
  précaution) : le montant suit alors chaque mois les dépenses (besoins et envies) du [budget](budget.md). Le champ
  suivant dépend de ce choix : **Montant à atteindre**, ou **Nombre de mois de dépenses** ;
- **Mois de départ** : ce mois-ci par défaut, ou un mois passé pour un objectif déjà commencé (par exemple une épargne
  entamée en janvier) ;
- **À atteindre en** : un mois après le mois de départ, et à venir ;
- **Ce que l'objectif mesure** (liste) :
    - le **patrimoine net** tout entier ;
    - **un niveau de la pyramide** (par exemple l'épargne de précaution) : la liste **Niveau de la pyramide** apparaît ;
    - **des comptes choisis** (par exemple le Livret A et le LDDS) : la liste des **Comptes** à cocher apparaît. Un
      crédit coché compte en moins.

Seuls les champs utiles au choix fait s'affichent ; sans JavaScript, tous s'affichent, chacun avec le cas auquel il
sert.

Le **point de départ** est la valeur mesurée le mois de départ : il fixe la trajectoire. Il ne change pas si vous
modifiez ensuite la cible ou la date ; il est recalculé si vous changez le mois de départ ou ce que l'objectif mesure.
Vingt objectifs au plus.

## Bonne route ou fausse route ?

Chaque mois, pour chaque objectif :

- l'**effort nécessaire** : ce qui manque, divisé par les mois restants (« il faut mettre de côté 450 € par mois
  jusqu'en novembre 2027 ») ;
- le **rythme constaté** : la progression moyenne des six derniers mois (moins si l'objectif est plus récent). Une
  moyenne lisse les hausses et les baisses de la bourse ;
- le **statut**, toujours écrit en toutes lettres :

| Statut | Quand |
|---|---|
| **Atteint** | le montant est atteint |
| **En avance** | le rythme dépasse l'effort nécessaire d'au moins 20 % |
| **Sur la bonne voie** | le rythme couvre l'effort nécessaire |
| **À surveiller** | le rythme couvre entre 80 % et 100 % de l'effort |
| **En retard** | le rythme couvre moins de 80 % de l'effort, ou la date est passée |
| **Trop tôt pour juger** | l'objectif date de ce mois-ci : le rythme se mesure à partir du mois suivant |

S'il faut en faire plus, Patrimeter dit **ce qui manque chaque mois** et le **mois où l'objectif serait atteint** à ce
rythme. Si des postes d'épargne du budget vont vers les comptes de l'objectif, il compare aussi **l'épargne prévue** à
l'effort nécessaire.

Sur la page **Objectifs**, chaque objectif se lit ainsi :

- un **anneau** donne la part de la cible atteinte (« 72 % atteint ») ; un **repère** sur l'anneau marque où la
  trajectoire prévue devrait vous placer ce mois-ci : l'anneau qui dépasse le repère est en avance, celui qui reste
  en deçà est en retard ;
- le **statut** en pastille, à côté du nom ;
- des **tuiles** pour les chiffres clés : montant actuel, cible et son mois, effort nécessaire et rythme constaté par
  mois ;
- un **graphique** du départ au mois cible : le réalisé (trait plein sur une aire colorée), la trajectoire prévue
  (tirets), la projection au rythme actuel (pointillés) et la cible (trait fin). Au survol ou au toucher, une
  bulle donne les montants du mois. Sous le graphique, une phrase reprend les chiffres (lecteurs d'écran, pages sans
  JavaScript) ;
- le statut des derniers mois.

## Où le voir

- **Page Objectifs** (barre principale) : chaque objectif, son statut, son graphique et ses derniers mois.
- **Après la saisie du mois** : un message pour chaque objectif dont le statut change (« Objectif « Apport » : En
  retard → Sur la bonne voie »).

Les objectifs sont chiffrés comme le reste de vos données, et font partie de l'[export](donnees.md). Comme le serveur
ne peut pas lire vos données en dehors de votre session, aucun bilan n'est envoyé par e-mail : le suivi se consulte
dans l'application.
