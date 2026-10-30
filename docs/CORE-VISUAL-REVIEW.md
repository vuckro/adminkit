# Revue visuelle du cœur WordPress — 29 octobre 2026

Périmètre : administration WordPress locale, hors Fluent Booking.

## Corrections

- Les paragraphes et listes de toutes les tables `.widefat` héritent maintenant de la couleur du design system, y compris les listes natives. La précédente exclusion de `.wp-list-table` laissait notamment les commentaires et les noms de fichiers sombres sur fond sombre.
- Les noms de fichiers de la médiathèque utilisent le rôle de texte secondaire.
- Un espacement de 16 px sépare les filtres de la médiathèque de la carte de liste.

## Vérifications

23 routes contrôlées en clair et sombre : tableau de bord, articles, pages, catégories, étiquettes, médias liste/grille, téléversement, commentaires, thèmes, extensions, comptes, profil, outils, import/export, réglages généraux, écriture, lecture, discussion, médias, permaliens et santé du site.

18 routes contrôlées à 390 px en sombre : aucun débordement horizontal du document. Captures conservées dans `output/playwright/core-mobile-*` du workspace.

Interactions exécutées : ouvrir puis annuler une réponse à un commentaire ; ouvrir/fermer le détail d’un média ; ouvrir/fermer le détail d’un thème. Aucun envoi, suppression, activation ou enregistrement de réglage.

Dépistage du contraste sur les éléments textuels terminaux avec leur fond opaque le plus proche : 46 combinaisons route/thème. Les résultats bruts sont conservés dans `output/native-core-contrast-results.json`. Ce dépistage ne constitue pas une certification WCAG : les fonds translucides, images et états cachés nécessitent une inspection visuelle. Les paginations désactivées et boutons de détail de thème masqués ont été écartés des résultats actionnables.

Contrôles statiques : contrats CSS et 4096 couleurs personnalisées validés.
