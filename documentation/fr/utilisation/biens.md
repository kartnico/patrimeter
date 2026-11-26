# Biens immobiliers

Onglet **Biens immobiliers** de [Mon compte](securite.md) : un tableau par bien, présenté comme la liste des
[comptes](comptes.md). Le bien d'abord, avec **votre part** de sa valeur et la valeur retenue pour vous, puis ses
crédits (chacun avec sa façon d'être compté et son capital restant dû), chacun avec son statut (**saisi** ou
**reporté**) ; en bas, sa **valeur nette**, celle que retient la pyramide. Le nom d'un bien ou d'un crédit ouvre sa
fiche. Les biens (résidence principale, location, SCPI, SCI…)
se créent ici seulement, avec leur part et leurs crédits ; le formulaire de compte ne les propose pas.

![Biens immobiliers](../captures/biens-ordinateur.png)

## Créer un bien et ses crédits

**Nouveau bien** crée en une fois le bien et ses crédits :

- **Type** : résidence principale, résidence secondaire, location meublée (LMNP) ou nue, SCPI, SCI, SIIC. Un clic
  sur le champ (ou sur sa flèche) affiche toute la liste ; tapez pour ne garder que les types qui contiennent le texte
  (« scpi », « location »…), sans tenir compte des majuscules ni des accents. Une **résidence secondaire** compte au
  niveau Immobilier de la pyramide, et ses crédits sont des crédits de résidence secondaire ;
- **Valeur totale du bien** : la valeur du bien entier, même si vous n'en possédez qu'une partie ;
- **Votre part (%)** : 100 % par défaut, 50 % pour un bien à deux…
- **Crédits** : nom et capital restant dû de chacun ; **Ajouter un crédit** pour un crédit de plus.

![Nouveau bien](../captures/nouveau-bien-ordinateur.png)

## Part et crédits

Chaque crédit lié se compte de l'une de deux façons :

| Le montant saisi est | Compté |
|---|---|
| **le total, compté selon ma part** (prorata) | capital restant dû × votre part du bien |
| **ma propre part** | tel quel |

Exemple : bien de 200 000 € détenu à 50 %, prêt commun de 100 000 € (au prorata) et prêt personnel de
10 000 € (votre part) :

- valeur retenue : 200 000 × 50 % = **100 000 €** ;
- crédits : 100 000 × 50 % + 10 000 = **60 000 €** ;
- valeur nette pour vous : **40 000 €**.

La part peut changer dans le temps (rachat de part, donation) : sur la page du bien, **Votre part du bien**
enregistre une nouvelle part à partir d'un mois. Un crédit existant se rattache à un bien depuis sa propre
page (**Bien financé**).
