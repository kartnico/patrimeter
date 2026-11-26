# Import CSV

Pour créer d'un coup vos comptes, crédits et biens immobiliers, sans les saisir un par un : un fichier CSV, écrit avec
un tableur (LibreOffice Calc, Excel, Numbers…) ou à la main, une ligne par compte. Page **Vos données**, depuis Mon
compte, section **Créer des comptes depuis un fichier CSV**.

## En bref

1. **Télécharger le modèle (CSV)** : un fichier prêt à remplir, avec un livret, un bien et son crédit en exemple.
2. Remplacez les exemples par vos comptes, une ligne chacun, et enregistrez au format CSV.
3. **Créer les comptes** avec ce fichier : vous arrivez sur la liste des comptes, qui les montre.

Les comptes **s'ajoutent** à ceux qui existent déjà. **Tout ou rien** : au moindre refus, rien n'est créé, et le
message indique la ligne en cause (la ligne 1 est celle des colonnes, comme dans le tableur). Fichier de 1 Mo et
500 comptes au plus.

## Exemple

```text
nom;type;ouverture;solde;mois;niveau;nature;part;bien;mode_credit;cloture
Livret A;livret_a;2020-01-15;12345,67;;;;;;;
Appartement Lyon;location_nue;2018-06-01;250000;;;;50;;;
Crédit appartement;credit_immo_locatif;2018-06-01;180000;;;;;Appartement Lyon;prorata;
Ancien PEL;pel_cel;2012-09-01;5000;2019-12;;;;;;2020-01-31
```

Seules les colonnes **nom**, **type** et **ouverture** sont obligatoires ; les autres peuvent manquer, dans
n'importe quel ordre. Une case vide vaut « non renseigné ».

## Colonnes

| Colonne | En anglais | Obligatoire | Contenu |
|---|---|---|---|
| `nom` | `name` | oui | Nom du compte, 80 caractères au plus ; deux comptes du fichier ne peuvent pas porter le même nom (majuscules et accents mis à part) |
| `type` | `type` | oui | Type de compte : son code (`livret_a`) ou son libellé (« Livret A », « Location nue »), en français ou en anglais ; [liste des types](#types-de-comptes) |
| `ouverture` | `opened_on` | oui | Date d'ouverture : `AAAA-MM-JJ` (2020-01-15) ou `JJ/MM/AAAA` (15/01/2020) |
| `solde` | `balance` | non | Solde en euros, au format de la langue de l'interface : `12345,67` ou `12 345,67` en français, `12,345.67` en anglais. Pour un crédit, le **capital restant dû**, positif. Pour un bien, sa **valeur totale**, même si vous n'en possédez qu'une part |
| `mois` | `month` | non | Mois du solde : `AAAA-MM` (2026-10) ou `MM/AAAA` (10/2026). Vide : le mois en cours (ou le mois d'ouverture, s'il est à venir) |
| `niveau` | `level` | non | Niveau de la pyramide, si ce n'est pas celui du type : `residence`, `precaution`, `fonds_euros`, `actions`, `immobilier` ou `exotique` |
| `nature` | `nature` | non | `actif` ou `passif` (`asset`, `liability`), si ce n'est pas celle du type |
| `part` | `share` | non | Pour un **bien** : votre part en pourcentage (`50`, `33,3333`), à partir du mois d'ouverture. Vide : 100 % |
| `bien` | `property` | non | Pour un **crédit** : le nom du bien qu'il finance, dans le fichier ou parmi vos biens déjà saisis |
| `mode_credit` | `loan_mode` | non | Pour un crédit lié à un bien : `prorata` (le montant est celui du crédit entier, compté selon votre part du bien) ou `own` (le montant est déjà votre part) ; vide : `prorata`. Voir [biens immobiliers](biens.md#part-et-credits) |
| `cloture` | `closed_on` | non | Date de clôture d'un compte fermé (`AAAA-MM-JJ` ou `JJ/MM/AAAA`) : il est créé puis archivé |

Les noms de colonnes acceptent les majuscules, les accents et les espaces (« Clôture », « Mode crédit »). Une colonne
inconnue est refusée, pour qu'une faute de frappe ne passe pas inaperçue.

Un seul solde par compte : l'historique des mois précédents se saisit ensuite, dans la [saisie du mois](saisie.md)
ou la fiche du compte.

## Enregistrer le fichier

- **Séparateur** : point-virgule (le choix d'un tableur en français), virgule ou tabulation ; Patrimeter reconnaît
  celui de la ligne des colonnes. Une valeur qui contient le séparateur s'écrit entre guillemets (`"Livret, joint"`).
- **Encodage** : UTF-8, avec ou sans BOM, ou Windows-1252 (le « CSV » d'Excel en français) ; les accents passent
  dans les deux cas.
- **Dates et mois** : un tableur transforme parfois `2026-10` en date (« oct.-26 ») ; mettez ces colonnes au format
  **Texte** avant de les remplir, ou écrivez `10/2026`.
- **LibreOffice Calc** : *Fichier > Enregistrer sous*, type « Texte CSV », cochez « Éditer les paramètres du
  filtre », jeu de caractères Unicode (UTF-8), séparateur `;`.
- **Excel** : *Fichier > Enregistrer sous*, type « CSV UTF-8 (délimité par des virgules) » ou « CSV (séparateur :
  point-virgule) ».

## Biens et crédits

Un **bien** est un actif du niveau Résidence principale ou Immobilier (types marqués **bien** ci-dessous) : sa
colonne `part` donne votre part. Un **crédit** (nature passif) se rattache à son bien par la colonne `bien`, qui
reprend le nom du bien, que le bien vienne avant ou après lui dans le fichier, ou soit déjà saisi dans Patrimeter.
Les biens et crédits ainsi créés apparaissent dans l'onglet [Biens immobiliers](biens.md), comme s'ils avaient été
saisis par **Nouveau bien**.

## Messages d'erreur

| Message | Que faire |
|---|---|
| Colonne « … » inconnue | Corriger le nom de la colonne, ou la retirer |
| Colonnes nom, type et ouverture obligatoires | Ajouter la colonne manquante, ou remplir la case vide de cette ligne |
| Type de compte « … » inconnu | Reprendre un code ou un libellé de la [liste des types](#types-de-comptes) |
| Nom « … » déjà utilisé à la ligne … | Donner un nom distinct à chaque compte du fichier |
| Date d'ouverture invalide, Mois du solde invalide | Écrire la date `AAAA-MM-JJ` ou `JJ/MM/AAAA`, le mois `AAAA-MM` ou `MM/AAAA` ; mettre la colonne au format Texte dans le tableur |
| Solde invalide | Un montant en euros, deux décimales au plus, sans autre texte que `€` |
| Part réservée aux biens immobiliers | Vider la colonne `part` pour un compte qui n'est pas un bien |
| Bien « … » introuvable | Écrire dans `bien` le nom exact du bien, tel que dans le fichier ou dans Patrimeter |
| Plus de valeurs que de colonnes | Vérifier le séparateur, et mettre entre guillemets une valeur qui le contient |

## Types de comptes

Code à écrire dans la colonne `type` (le libellé, en français ou en anglais, convient aussi) ; le niveau et la nature
sont ceux que prend le compte quand les colonnes `niveau` et `nature` sont vides.

| Code | Libellé | Niveau | Nature |
|---|---|---|---|
| `residence_principale` | Résidence principale (valeur du bien) | Résidence principale (`residence`) | actif, **bien** |
| `credit_immo_rp` | Crédit immobilier résidence principale | Résidence principale (`residence`) | passif (crédit) |
| `compte_courant` | Compte courant | Précaution (`precaution`) | actif |
| `livret_a` | Livret A | Précaution (`precaution`) | actif |
| `ldds` | LDDS | Précaution (`precaution`) | actif |
| `lep` | LEP | Précaution (`precaution`) | actif |
| `livret_bancaire` | Livret bancaire / compte à terme | Précaution (`precaution`) | actif |
| `pel_cel` | PEL / CEL | Précaution (`precaution`) | actif |
| `credit_conso` | Crédit à la consommation | Précaution (`precaution`) | passif (crédit) |
| `autre_passif` | Autre passif | Précaution (`precaution`) | passif (crédit) |
| `fonds_euros` | Assurance-vie — fonds euros | Fonds euros (`fonds_euros`) | actif |
| `av_uc` | Assurance-vie — unités de compte | Actions (`actions`) | actif |
| `pea` | PEA / PEA-PME | Actions (`actions`) | actif |
| `cto` | Compte-titres (CTO) | Actions (`actions`) | actif |
| `per` | PER | Actions (`actions`) | actif |
| `gestion_pilotee` | Gestion pilotée | Actions (`actions`) | actif |
| `epargne_salariale` | Épargne salariale (PEE, PERCO) | Actions (`actions`) | actif |
| `residence_secondaire` | Résidence secondaire | Immobilier (`immobilier`) | actif, **bien** |
| `lmnp` | LMNP | Immobilier (`immobilier`) | actif, **bien** |
| `location_nue` | Location nue | Immobilier (`immobilier`) | actif, **bien** |
| `scpi` | SCPI | Immobilier (`immobilier`) | actif, **bien** |
| `sci` | SCI | Immobilier (`immobilier`) | actif, **bien** |
| `siic` | SIIC (foncières cotées) | Immobilier (`immobilier`) | actif, **bien** |
| `credit_immo_rs` | Crédit immobilier résidence secondaire | Immobilier (`immobilier`) | passif (crédit) |
| `credit_immo_locatif` | Crédit immobilier locatif | Immobilier (`immobilier`) | passif (crédit) |
| `crowdfunding` | Crowdfunding | Exotique (`exotique`) | actif |
| `crypto` | Cryptomonnaies | Exotique (`exotique`) | actif |
| `gfi` | GFI / GFV | Exotique (`exotique`) | actif |
| `or` | Or | Exotique (`exotique`) | actif |
| `argent_metal` | Argent (métal) | Exotique (`exotique`) | actif |
| `autres_metaux` | Platine, palladium et autres métaux précieux | Exotique (`exotique`) | actif |
| `bijoux` | Bijoux | Exotique (`exotique`) | actif |
| `montres` | Montres | Exotique (`exotique`) | actif |
| `art_collection` | Œuvres d'art et objets de collection | Exotique (`exotique`) | actif |
| `vins` | Vins et spiritueux | Exotique (`exotique`) | actif |
| `voiture` | Voiture | Exotique (`exotique`) | actif |
| `moto` | Moto | Exotique (`exotique`) | actif |
| `autre_actif` | Autre actif | Exotique (`exotique`) | actif |
