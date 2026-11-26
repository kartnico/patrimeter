# Jeu de référence de non-régression

Ces fichiers figent les résultats de calcul de la **v1** de Patrimeter (commit `e421782`, PHP natif),
produits par le code v1 lui-même avant le portage vers Symfony. Les tests de non-régression
(`tests/Regression/`) recalculent tout avec le code courant et exigent un résultat identique.

| Fichier | Contenu |
|---|---|
| `v1-dataset.json` | 14 comptes (6 niveaux, actifs et passifs, comptes clôturés, compte ouvert en cours de période) et 299 relevés de 2022-12 à 2025-12, avec des mois manquants (reports) et des relevés hors période d'activité |
| `v1-expected.json` | Pour chaque mois de 2022-11 à 2026-02 : patrimoine net, actifs, passifs, nombre de valeurs saisies et reportées, et par niveau : actifs, passifs, net, part du total, détail des valeurs par compte |
| `v1-pyramid-*.svg` | Rendu SVG de la pyramide pour trois mois représentatifs (total négatif, niveau négatif, fin de période) |

Montants en centimes, mois au format `AAAA-MM`, parts en pourcentage arrondies à 6 décimales.

**Ne jamais modifier ces fichiers pour faire passer un test.** Un écart signale une régression.
Si un changement de règle métier est voulu, ajouter un nouveau jeu de référence (`v2-…`) et
documenter la raison dans le CHANGELOG.
