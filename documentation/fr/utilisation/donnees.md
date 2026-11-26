# Export et import de vos données

Page **Vos données**, depuis Mon compte : pour déménager sur une autre instance de Patrimeter, ou garder une
copie de vos données.

![Vos données](../captures/donnees-ordinateur.png)

## Exporter

**Télécharger mes données**, après avoir confirmé votre identité : un fichier JSON contient vos comptes et
crédits, leurs relevés, les parts de vos biens, les liens entre biens et crédits, et le [budget du foyer](budget.md)
(foyer, catégories, postes et montants de chaque mois).

**Le fichier n'est pas chiffré** : gardez-le en lieu sûr et supprimez-le après usage.

Votre compte utilisateur (adresse, mot de passe, second facteur, passkeys, jetons d'API) n'est pas exporté :
il se recrée sur l'autre instance.

## Compte famille

Le même fichier sert au [compte famille](famille.md) : exporté chaque mois, il est importé par le compte famille
pour vous, dans **Membres de la famille**, où il remplace le précédent import. Le compte famille ne garde que ce que
contient le fichier, chiffré avec sa propre clé.

## Créer des comptes depuis un fichier CSV

Pour saisir d'un coup vos comptes, crédits et biens immobiliers, depuis un tableur : **Télécharger le modèle (CSV)**,
remplissez-le (une ligne par compte), puis **Créer les comptes**. Les comptes s'ajoutent à ceux qui existent ; tout
ou rien. Format, colonnes et codes des types : [import CSV](import-csv.md).

## Importer

Sur l'autre instance, créez votre compte, puis **Vos données** → **Importer** avec le fichier :

- l'import se fait dans un compte **encore vide** ;
- **tout ou rien** : au moindre problème, rien n'est importé, et le compte en cause est indiqué ;
- fichier de 2 Mo au plus.

Format détaillé du fichier : [export et import](../exploitation/export.md).
