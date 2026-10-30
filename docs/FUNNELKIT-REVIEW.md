# FunnelKit — intégration AdminKit

Revue locale des 29–30 octobre 2026 sur WordPress 7.1.4 : FunnelKit Builder 3.17.0.1 et FunnelKit Automations Lite 3.8.6.2. Les deux extensions étaient installées ; elles ont été activées après sauvegarde locale de la base.

## Portée et architecture

Deux adaptateurs Tier B indépendants, découverts automatiquement, limités à la version majeure 3. Les constantes `WFFN_VERSION` et `BWFAN_VERSION` détectent les hôtes. Les classes de corps et les styles de contenu ne sont ajoutés que sur leurs écrans. Le petit habillage du menu WordPress est partagé dans l’administration. Une future version majeure utilise le repli natif.

- `funnel-builder/css/shared.css` : composants BWF communs, portails, formulaires, navigation, tableaux, avis, menus, fenêtres, canevas et adaptation mobile.
- `funnel-builder/css/controls.css` : contrôles natifs, libellés, validation, sélections, recherche et fenêtres imbriquées ; chargé après le skin de chaque application.
- `funnel-builder/css/menu.css` : icônes Lucide funnel/workflow et liens de mise à niveau neutres ; conserve les icônes personnalisées du Menu Manager.
- `funnel-builder/css/admin.css` : étapes de tunnel et fenêtre plein écran Builder.
- `wp-marketing-automations/css/admin.css` : éditeur d’automatisation, minimap, profil contact, notes et disposition mobile.
- `funnel-builder/js/shared.js` : accès clavier aux onglets `div` et aux segments `span` de l’hôte. Flèches, Début/Fin et Entrée/Espace utilisent les gestionnaires de clic natifs. Les changements React resynchronisent les attributs accessibles via un observateur limité aux insertions et classes.

Automations référence les fichiers communs situés dans le dossier de l’adaptateur Builder. Ils sont livrés avec AdminKit et se chargent même si l’extension Builder est inactive. Le skin ne dépend pas de l’activation de son adaptateur compagnon. Aucun chargement de police, bibliothèque ou CDN supplémentaire.

Toutes les couleurs viennent des rôles AdminKit. Le skin conserve les nonces, REST, permissions, licences, données et gestionnaires de l’hôte. Il n’habille pas les pages publiques de vente/checkout, les documents d’e-mail ou les contenus des constructeurs de pages. Les aperçus de modèles et données d’exemple protégées restent natifs et verrouillés.

## Corrections

Surfaces et textes fixes remplacés dans les tableaux de bord, cartes, tableaux, champs, notifications et fenêtres portalisées, y compris les éditeurs plein écran placés hors du conteneur WordPress. Les états sélectionnés utilisent des surfaces neutres ; les avertissements conservent leur signification. Les actions, icônes de fermeture, menus d’ellipse, aperçus et indicateurs de canevas restent lisibles en sombre.

Les limites mobiles de l’hôte incluaient un `body` de 1 280 px et un décalage de champs de 320 px forcés en CSS. L’adaptateur neutralise ces règles, permet aux champs et cartes de rétrécir, transforme les barres latérales en navigation horizontale et garde le défilement des tableaux à l’intérieur de leur conteneur. Les en-têtes du profil se répartissent sur plusieurs lignes. La fermeture de l’éditeur de tunnel reste disponible sur mobile.

Les transitions de couleur durent 120 ms ; les petites fenêtres ont une entrée de 140 ms. Ces ajouts sont réservés à `prefers-reduced-motion: no-preference`. Aucun masque de chargement, délai artificiel ou moteur automatique de couleurs n’est ajouté.

Les 34 déclarations `!important` des fichiers Builder/communs répondent aux priorités imposées par BWF : titre, boutons, navigation, annonces, notifications, surfaces en ligne des libellés de réglage, des transitions forcées et deux contraintes de mise en page. Automations ajoute 5 exceptions pour la couleur en ligne d’un statut de contact, le fond forcé d’ajout d’étiquette, un lien de notification Lite et le séparateur mobile des réglages et les séparateurs forcés du profil. Les sept exceptions communes supplémentaires couvrent les bordures de formulaire, libellés requis/erreurs, catégories, séparateurs de tableau, surfaces Sublium et deux couleurs en ligne du menu. Les budgets sont documentés dans `dev/adapter-audit.php` ; aucun littéral de couleur dans les skins.

## Données de contrôle

Le manifeste se trouve dans `output/funnelkit-review/fixtures.json` du workspace.

- Tunnel 1 « [Démo AdminKit] Tunnel de contrôle » désactivé ; pages 156, 157 et 158 en brouillon. Aucun remplacement du checkout global.
- Automatisation 1 « [Démo AdminKit] Contrôle inactif » inactive ; déclencheur WooCommerce Order Created avec statut Terminée, sauvegardé puis rouvert. Aucune action d’envoi.
- Contact 1 `controle-adminkit@example.invalid`, non vérifié, sans case d’abonnement cochée. Nom modifié dans l’interface, sauvegardé et relu. Une note privée est enregistrée ; « Send Note to Contact » est resté désactivé.

Les assistants Builder et Automations ont été parcourus sans accepter la télémétrie, les installations recommandées ou l’inscription à une newsletter. Aucun SMTP, paiement, campagne ou envoi commercial configuré par cette intégration. Builder a atteint 3/3 étapes ; le bouton final « Setup Checkout » n’a pas été appliqué après le refus du contrôle automatique d’approbation concernant une possible modification persistante du checkout.

## Vérification et limites

51 routes ont été parcourues sur ordinateur en clair/sombre ; 20 routes ont été reprises à 390 px, avec corrections ciblées des défauts détectés. Les parcours couvrent les écrans principaux de Builder et Automations, les sous-réglages, galeries, paniers, listes/tags/champs, profil et notes, ainsi que les éditeurs de tunnel et d’automatisation. Les captures et rapports de contraste/débordement sont conservés dans `output/funnelkit-review/`. Le dépistage des textes terminaux est complété par l’examen des captures et des interactions ; ce n’est pas une certification WCAG.

Les fonctionnalités Pro sont absentes de cette installation. Leurs portes d’accès et fenêtres de mise à niveau sont conservées et habillées ; leurs éditeurs complets, leurs envois et leurs intégrations commerciales ne sont pas validés. Les offres SMTP/Stripe/Subscriptions ne sont pas installées pour réaliser les tests.

La dernière passe sombre à 390 px sur les 20 routes ne remonte plus de débordement du document ni de défaut au dépistage des textes contrôlés. Les parcours au clavier ont été vérifiés sur les onglets Automations et les sous-onglets Pixel Tracking de Builder.

Les baselines de dérive correspondent aux versions installées. À chaque mise à jour de l’hôte, exécuter `dev/adapter-drift.php` avec le chemin de l’extension, puis refaire les interactions et captures pertinentes. Les fonctions de gestion restent natives : ne pas élargir les sélecteurs aux contenus des e-mails ou aux pages publiques.

## Reprise détaillée du 30 octobre

Les captures utilisateur ont révélé des lacunes du premier dépistage des seuls textes terminaux. Le contrôle complémentaire lit désormais les nœuds de texte propres aux éléments, même s’ils contiennent des icônes ou d’autres enfants, et inspecte séparément les dégradés et les glyphes. Le rapport précédent décrit une couverture de navigation, pas une validation exhaustive des états.

Corrections : rectangle intérieur de l’avis de démarrage, cases/radios de 18 px sans conflit avec les interrupteurs globaux, libellés/titres/annulation, recherche d’étiquette et liste, validation et avis Pro, texte de sélection et menus, séparateurs du profil, ajout de tags/listes, date/adresse, et page Subscriptions sur toute sa hauteur. Les fenêtres d’événement restent utilisables à 390 px. Les noms accessibles sont dérivés des libellés natifs ; aucun texte métier ajouté.

WooCommerce avait été désactivé et a été réactivé avec l’autorisation de l’utilisateur. Le formulaire Order Created a ensuite été vérifié avec ses champs effectivement chargés. Les essais de statut et de type de produit ont été annulés, les sélections vidées ; aucune automatisation activée, aucun contact créé et aucun envoi effectué pendant cette reprise.

Preuves dans `output/funnelkit-review/followup/` : 16 captures/positions en clair et sombre, puis 12 états d’interaction (sélection, menus d’aide et de ligne, validation, recherche, fonction Pro). Aucun défaut de contraste textuel ni débordement du document remonté dans ces états contrôlés ; ceci reste un dépistage ciblé, pas une certification WCAG ni une validation des fonctions Pro.

## Polissage des états secondaires

Nouvelle passe sur les étapes Builder, Purchases/Funnels du contact de contrôle, dashboard Automations, workflow et réglages : fonds au survol et pendant le déplacement, sous-cartes, ombres de contour, icônes d’en-tête et de nœud, fermeture de workflow, états vides, segments Sales/Optin et bouton de licence. Les glyphes héritent explicitement leur couleur : la règle universelle de l’hôte appliquait auparavant sa couleur à chaque chemin SVG malgré le `currentColor` du parent.

Les titres éditables conservent leur gestion native ; le contour blanc permanent est remplacé par des états repos/survol/focus. Huit exceptions host-forced supplémentaires portent sur les fonds des sous-étapes/fermetures, les ombres de carte et les bordures/ombres de titre et les couleurs forcées du menu de suppression. Les autres changements utilisent la spécificité normale et les rôles existants.

La fenêtre « Dismiss Setup » est corrigée à partir du composant natif et de la capture utilisateur. Son ouverture a été refusée par le contrôle automatique d’approbation ; aucun abandon d’assistant n’a été confirmé. Après autorisation explicite de l’utilisateur, son rendu a été vérifié en clair/sombre puis la fenêtre a été fermée avec Échap, sans cliquer sur « I’ll do it later ». Captures et résultat dans `followup/dismiss-confirmation*`.

Rapports ciblés : `followup/polish-final.log` (12 états clair/sombre), `followup/polish-actions.log` (survol, menus, focus, segments et mobile). Les contenus Pro, chiffres d’exemple floutés et documents d’e-mail restent gérés par FunnelKit.

## Emails, filtres et menu Tools

Reprise des écrans `/history`, `/transactional-emails`, `/templates` et `/global-style` : menu d’en-tête Tools, titres et icônes de filtre, fenêtres Source/Status/Time Period, titre transactionnel, avis informatif, bordures des cartes et libellés des styles globaux. Le wrapper commun de cette famille restait en deux colonnes sur mobile ; il est désormais empilé, avec les tableaux dans leur conteneur de défilement. Les aperçus et éditeurs Pro restent désactivés par l’hôte ; aucun contournement ni envoi de test.

Le menu Tools expose un déclencheur clavier distinct des liens : Entrée/Espace basculent son ouverture, Flèche bas place le focus sur le premier lien, Échap ferme et rend le focus au déclencheur. Les événements rejoignent les gestionnaires natifs de FunnelKit. Les filtres qui étaient des `div` cliquables reçoivent le rôle bouton et Entrée/Espace. Aucun libellé métier ajouté.

Preuves : `followup/email-review-complete.log` (20 états, clair/sombre, ordinateur et mobile, sans défaut au dépistage textuel ni débordement du document), captures `email-*`, et `email-keyboard-proof.log`. Les documents d’e-mail et leurs aperçus intégrés restent natifs. Aucune modification de contenu, activation d’email ni sauvegarde des styles globaux.
