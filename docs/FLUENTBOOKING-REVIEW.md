# FluentBooking — recette du 29 octobre 2026

Cible : WordPress local `localhost:10013`, AdminKit, FluentBooking Free 2.5.0.
Les corrections sont appliquées à l’installation locale et présentes dans les
sources du projet. Aucun site distant n’a été modifié.

## Corrections vérifiées

- Pont des nouveaux tokens FluentBooking 2.5, des anciens alias et d’Element Plus
  vers les rôles AdminKit : surfaces, textes, accent, statuts et bordures.
- Thème clair/sombre synchronisé sur les classes `dark` et `fluent_theme_dark`,
  y compris après un changement de thème sans rechargement.
- Police, géométrie et densité des champs ; 32 px sur ordinateur et 44 px sur
  mobile. Les petits calendriers gardent une grille compacte à sept colonnes.
- États désactivés neutres, glyphes statistiques lisibles et focus clavier.
- Un seul défilement principal ; menu replié aux largeurs intermédiaires pour
  préserver la place occupée par la barre latérale WordPress.
- Cartes et actions sans débordement à 320 px ; navigation des réglages
  d’événement accessible sur mobile et tablette.
- Fenêtres centrées, actions repliables, titres longs séparés du bouton de
  fermeture ; champs date/heure empilés sur petit écran.
- Calendrier déroulant ajusté à la largeur du champ dans la fenêtre mobile.
- TinyMCE : tokens, police et couleurs dans l’iframe ; thème et accent du parent ;
  bouton de codes courts séparé des outils média sur mobile.
- Invitations à passer en version Pro présentées comme informations, avec les
  boutons utilisant l’accent actif. Les marques fournisseur restent distinctes.

## Preuves

La matrice principale contient **50 cas** : calendriers, réservations, réglages
  généraux, détails d’événement et notifications par courriel, chacun en clair
  et sombre à **320, 390, 768, 1024 et 1440 px**. Les mesures finales ne détectent
  aucun débordement horizontal du document ou du conteneur principal et aucune
  classe sombre résiduelle en thème clair. Le défilement horizontal des liens
  de navigation et des filtres est intentionnel et reste dans son conteneur.

Les captures individuelles, planches de comparaison et mesures sont conservées
  dans [`output/fluent-booking-visual-review`](../../output/fluent-booking-visual-review/).
  La source des mesures est `matrix.json`. Les captures sont des images du
  navigateur local connecté ; elles ne remplacent pas les tests métier.

Contrôles complémentaires : accueil initial et tableau de bord avec graphique,
  disponibilités vides et avec programme, limites, questions, sélection de date,
  réservation manuelle sans soumission, fenêtre de notification sans envoi,
  équipes, paiement et intégrations, licence, modules et écran MCP. Le menu a
  été ouvert au point de rupture 1024 px et parcouru au clavier ; le focus
  présente un contour visible de 2 px. Le thème de TinyMCE a été vérifié après
  deux bascules successives du thème parent. Aucun message d’erreur JavaScript
  n’a été relevé dans le journal de la session finale.

Les cinq écrans de la matrice ne couvrent pas tous les états métier possibles.
Les pages réservées à Pro ont été vérifiées dans leur état verrouillé natif :
les formulaires Pro éditables, synchronisations externes, paiements et livraisons
réelles de courriels ne sont pas validés par cette recette.

## Données de recette

Un événement local « Recette visuelle AdminKit » a permis d’inspecter les écrans
qui nécessitent un calendrier. Son calendrier et sa disponibilité ont été
sauvegardés, puis retirés avec des contrôles d’identité et de dépendance. Aucune
réservation n’a été créée. Le retour aux listes vides et à l’accueil initial a
été confirmé après rechargement. Aucun email de notification n’a été envoyé.

## Vérifications techniques

PHP et JavaScript : syntaxe valide. Génération des tokens à jour, `dev/check.php`,
`dev/css-contract.php`, audit des adaptateurs et détection Impeccable réussis.
L’adaptateur reste sous son plafond de surcharges : 107 `!important`, plafond 108.
Le nouveau `baseline.json` décrit les neuf feuilles CSS de FluentBooking 2.5.0 ;
le contrôle de dérive correspond à l’installation réellement inspectée.

## Retouches après captures annotées

La bordure externe du bloc de recommandations d’accueil utilise désormais
`--ak-border`, en remplacement du gris fixe clair du plugin. Le radio natif
sous le sélecteur Liste/Calendrier est explicitement rendu invisible et découpé,
tout en restant accessible au clavier, pour prévenir le petit relief signalé.
Cette seconde cause est déduite de la capture et de la cascade CSS du composant.

Ces deux retouches ont été appliquées localement avec sauvegarde et lecture de
contrôle. Le contrat CSS et le budget de surcharges passent. Elles n’ont pas
encore été revérifiées visuellement : le transport du navigateur était fermé
lors de cette intervention. La matrice de 50 cas ci-dessus précède ces retouches.
