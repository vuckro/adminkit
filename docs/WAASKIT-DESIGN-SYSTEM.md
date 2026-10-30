# WaasKit — système actuel

AdminKit utilise le design system publié sur [Design WaasKit](https://design.waaskit.site/), relevé le 29 octobre 2026. La source précise est [l’export CSS natif](https://design.waaskit.site/wp-content/uploads/waaskit-exports/wk-ui.css). Les composants marketing de cet export ne sont pas chargés dans WordPress.

Le fichier `tokens/palettes/waaskit.json` conserve la provenance et son SHA-256, les 74 variables stables, 82 variables contextuelles claires et 47 substitutions sombres. Le build ne distribue que les variables utilisées et leurs dépendances ; le relevé complet reste dans Git. Il remplace les anciennes palettes Bricks jaune/orange. Le preset monochrome WaasKit est le défaut : encre sombre en clair, encre claire en sombre. Le bleu WaasKit reste un choix explicite, avec une chromaticité 0.17. Les anciennes valeurs enregistrées `adminkit` continuent à sélectionner ce bleu ; une valeur absente ne sélectionne plus automatiquement un fournisseur. Les valeurs RGB nécessaires aux anciennes variables WordPress sont relevées sur les mêmes couleurs, puis conservées dans `legacy_rgb`.

- Couleurs OKLCH, surfaces et textes sémantiques distincts.
- Geist et Geist Mono, hébergées localement avec leurs licences SIL OFL.
- Rayons, ombres et focus issus des variables `--wk-*`.
- Thème piloté par `data-adminkit-theme` ; aucun nouveau mécanisme de thème.
- Les composants existants consomment toujours `--ak-*` : leurs fonctions et le modèle WordPress restent identiques.
- La densité des contrôles natifs reste compacte (12/13/14 px) ; les grands espacements du site public ne sont pas imposés à wp-admin.

Les champs utilisent une bordure de repos discrète via `--ak-input-border`, affinée le 29 octobre à la demande du mainteneur. Le focus clavier utilise les deux anneaux du système. Les couleurs de statut consommées comme texte utilisent les rôles `*-text`, leurs fonds utilisent `*-soft`.

Le choix de couleur personnalisée reste disponible et ne remplace que sa famille d’accent. La source Bricks est une option explicite de compatibilité ; elle ne définit plus le thème par défaut.

## Mise à jour

Modifier la source JSON, puis exécuter :

```sh
php tokens/build.php
php tokens/build.php --check
```

Les fichiers `assets/css/waaskit-tokens.css` (administration et aperçu) et `assets/css/waaskit-toolbar-tokens.css` (barre publique uniquement) sont générés et ne doivent jamais être édités directement. `assets/css/tokens.css` est également généré, depuis `tokens/adminkit.json` (rôles et densité) et `tokens/appearance.css` (variantes personnalisées) et `tokens/wordpress.css` (correspondance WordPress). Les polices sont déclarées dans `assets/css/fonts.css`.

## Ajustements des composants d’administration — 29 octobre 2026

Les contrôles natifs partagent `--ak-control-height` (32 px) et
`--ak-control-height-touch` (44 px sous 783 px). `--ak-muted` est un alias de
compatibilité vers `--ak-text-muted` pour les outils de navigation existants.
Ces valeurs de densité restent propres à l’administration ; la palette publique
WaasKit n’est pas modifiée.

Les états désactivés AdminKit utilisent les surfaces et textes sémantiques sans
atténuer tout le bouton. Les hovers primaires associent leur fond à
`--ak-on-accent-hover`. Les boutons d’actions groupées conservent le focus clavier
malgré leurs sélecteurs WordPress par identifiant.

L’adaptateur Fluent Booking utilise le RGB actif d’AdminKit et le rôle
`--ak-primary-text` pour l’encre. Le bandeau d’accueil utilise la teinte douce et
thématise explicitement ses titres et paragraphes. Les libellés du gestionnaire
de menu restent lisibles sur petit écran grâce au repli des outils.

Validation locale : revue de 17 routes ; matrice clair/sombre à 390 et 1200 px
sur neuf routes, puis contrôles à 320, 768 et 1440 px sur les sept surfaces
principales concernées. Aucun débordement horizontal du document détecté.
Les captures sont dans `output/playwright/` du dossier de travail Adminkit.
La validation porte sur les écrans et plugins actifs visités, pas sur tous les
plugins optionnels distribués. Aucun réglage métier ni contenu n’a été enregistré.

### FluentBooking — contrôles

Le pont Element Plus reprend désormais la police, les rayons, la densité et le
voile modal AdminKit. Les champs utilisent le fond de saisie et la bordure forte ;
les états désactivés gardent une surface neutre et le focus clavier reste visible.
Les teintes de statut et les lignes survolées utilisent les rôles sémantiques.
Les fenêtres sont contraintes à la largeur mobile. Ces ajustements concernent
uniquement l’administration FluentBooking, pas les formulaires publics.

## Interactions natives — seconde passe du 29 octobre 2026

Le focus des champs conserve leur surface. La bordure et l’anneau WaasKit
signalent le focus sans appliquer une teinte d’accent au fond. Les textes
indicatifs et désactivés ont leurs rôles `--ak-text-subtle` et
`--ak-text-disabled`. Le curseur des interrupteurs cochés utilise
`--ak-on-accent`, y compris en sombre.

La grille de modification rapide prend en charge le conteneur
`.inline-edit-wrapper` ajouté par WordPress et suit la largeur disponible par
requêtes de conteneur. Les catégories, titres de sélection groupée et actions
de modération lisent les rôles sémantiques dans les deux thèmes. Les filtres de
statut mobiles restent accessibles par défilement horizontal.

Vérifications interactives locales réalisées dans Chromium :

- 14 écrans, deux thèmes, 52 vérifications de champs au premier audit ; les
  défauts détectés sur les comptes et les commentaires ont été corrigés puis
  vérifiés de nouveau.
- Ouverture, focus, survol des boutons et annulation des modifications rapides
  d’articles/pages/catégories ; modifications groupées et réponse aux commentaires.
- Survol/appui et aller-retour du menu replié, panneaux d’options, ouverture et
  fermeture des sélecteurs médias et des détails des thèmes.
- Activation/désactivation conditionnelle des listes de lecture, sans enregistrer.
- Clic du champ Étiquettes et aller-retour des interrupteurs à 320, 390 et 1440 px.
- Recherche de blocs et champs d’étiquettes de Gutenberg, ainsi que le formulaire
  de connexion et sa case « Se souvenir de moi », sans soumission.

Les scripts et captures de cette passe sont conservés dans `output/` du dossier
Adminkit. `adminkit-wordpress-interactions.patch` contient uniquement cette passe
WordPress. Les originaux sont dans `output/pre-interactions/`. Les contrôles
`tokens/build.php --check`, `dev/check.php` et `dev/css-contract.php` passent.
Le détecteur Impeccable signale le marqueur latéral préexistant du menu actif :
il est conservé comme repère de navigation, sans ajout de bordure décorative.

Fluent Booking est exclu de cette seconde passe à la demande du mainteneur.
L’audit global des adaptateurs signale actuellement un dépassement de budget
sur cet adaptateur hors périmètre ; cette passe ne modifie ni son budget ni
ses styles. La validation couvre les états visités dans Chromium et ne constitue
pas une certification de tous les navigateurs, rôles ou plugins facultatifs.

## Contraste et Lucide — 29 octobre 2026

Les bordures des champs, listes déroulantes et de la pagination sont plus
légères au repos. Les icônes utilisent Lucide et les rôles `--ak-icon` /
`--ak-icon-active`. Le focus et les textes conservent leur lisibilité.
Le catalogue de 2 275 SVG, ses licences et le fonctionnement du sélecteur sont
décrits dans [ICONS.md](ICONS.md).

### Recette visuelle FluentBooking 2.5.0

L’adaptateur couvre aussi les nouveaux rôles `--fcal-surface`, `--fcal-text`,
`--fcal-accent` et les familles de statut de FluentBooking 2.5, en plus des
anciens alias et d’Element Plus. La synchronisation retire les classes sombres
FluentBooking lorsqu’AdminKit passe en clair. Les éditeurs TinyMCE chargent les
feuilles de tokens et reçoivent le thème et l’accent du document parent.

La recette a corrigé le double défilement, les débordements à 320 et 1024 px,
les actions et calendriers des fenêtres mobiles, les titres longs et les outils
de l’éditeur. La navigation des réglages d’événement reste accessible sur petit
écran. La matrice principale couvre 50 cas : cinq écrans, cinq largeurs, deux
thèmes. Voir [la recette détaillée](FLUENTBOOKING-REVIEW.md) pour les preuves et
les limites liées à la version gratuite installée.

### Default color fidelity

Default light and dark color roles remain those of the upstream export recorded in `tokens/palettes/waaskit.json`. The eleven dark neutral roles use WaasKit’s OKLCH values, including subtle chroma; they must not be replaced by approximate pure-gray hex values. On 2026-10-31 the live export matched the recorded SHA-256 and these roles were restored. Component shadow splitting remains a local, visually equivalent adaptation. Custom saved overrides still take precedence.
