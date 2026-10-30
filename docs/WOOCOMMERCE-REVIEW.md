# WooCommerce — prise en charge AdminKit

Revue locale du 29 octobre 2026 : WordPress 7.1.4, WooCommerce 11.2.1, PHP-FPM 8.2.35. Adaptateur Tier B, compatible avec les versions majeures 10 et 11 ; une future version majeure conserve le repli natif.

## Portée

Cette intégration habille l’administration WooCommerce. Elle conserve les formulaires, permissions, nonces, REST, calculs, données et actions WooCommerce. Elle ne construit pas un thème de boutique ou une passerelle de paiement.

- Installation existante activée, installation des tables et pages WooCommerce native, assistant parcouru jusqu’à sa sortie ; extensions facultatives ignorées, partage de données et inscription marketing non acceptés.
- Accueil, listes de tâches et notifications, statistiques, graphiques, états sans données, clients, marketing.
- Produits simples et variables, prix, inventaire, expédition, attributs/variations, téléchargement, médias, catégories, marques, avis, coupons, import et export CSV.
- Commandes HPOS : liste, détail, lignes, totaux, client, notes et fenêtres d’ajout de produits. Action principale pleine largeur avant la corbeille, avec ordre clavier identique.
- Réglages généraux, produits, TVA et grille de taux, zones/modes/classes d’expédition, paiements et formulaires hors ligne, comptes/confidentialité, e-mails et aperçu, visibilité, intégration, réglages avancés.
- État du système, outils, journaux et tâches planifiées.
- SelectWoo dans les écrans WooCommerce et les adresses clients des profils WordPress. Badge de visibilité dans les barres d’administration et du site.

## Organisation

`class-woocommerce.php` : chargement conditionnel, limite de version, classe `adminkit-woocommerce`, icônes Lucide des onglets produit.

- `css/wc-admin.css` : application React existante.
- `css/admin.css` : surfaces classiques.
- `css/modern.css` : pont WPDS/DataViews WooCommerce 11, onboarding, interactions et finitions communes.
- `css/select2.css` : champs portalisés, résultats et messages ; également chargé sur les profils clients.
- `css/toolbar.css` : badge de boutique, chargé seulement quand la barre est présente, dans les contextes admin/frontend.
- `css/dashboard.css` : widgets WooCommerce du tableau de bord WordPress.
- `js/admin.js` : ordre DOM des actions HPOS, défilement des grilles de livraison/TVA et correction des minimums en ligne des selects natifs masqués par SelectWoo. Aucun remplacement AJAX des comportements WooCommerce.

Toutes les couleurs passent par les rôles AdminKit. Les transitions ajoutées durent 120 ms et respectent `prefers-reduced-motion`. Aucun nouveau `!important` : budget de l’adaptateur maintenu à 3. La règle du badge a été déplacée vers toolbar.css pour couvrir aussi les pages React et la barre du site.

## Corrections observées

L’ancienne borne majeure 10 désactivait toute la skin avec WooCommerce 11. Le pont couvre maintenant le nouveau vocabulaire WPDS. Les libellés d’onboarding, tâches, statistiques et paiements, les notes de commande créées après interaction, les champs de commande, grilles et messages SelectWoo utilisent les bonnes surfaces et couleurs dans les deux thèmes.

Les onglets des réglages restent sur une ligne défilante ; les grilles larges défilent dans leur propre conteneur. Les fieldsets d’e-mail peuvent rétrécir. Les selects natifs cachés ne conservent plus leur minimum en ligne de 350 px, qui élargissait le document sur mobile.

## Vérifications exécutées

41 routes distinctes parcourues sur ordinateur en clair/sombre, avec captures et dépistage du contraste des textes terminaux. 23 routes distinctes parcourues à 390 px en clair/sombre, avec reprises des débordements identifiés. Le dépistage automatisé n’est pas une certification WCAG ; il est complété par l’inspection des captures et des états ouverts.

Interactions : assistant, choix de pays, refus des offres facultatives, onglets produits, ouverture de variation, passage virtuel/physique et téléchargeable, sélecteurs et modales produits/livraison, création d’une note privée. Prix du produit privé passé de 129 à 139, sauvegardé et relu, puis restauré à 129 dans l’interface. Les états virtuel et non téléchargeable ont également été restaurés puis sauvegardés. Aucun remboursement ou paiement exécuté, aucune connexion commerciale, aucun envoi d’e-mail déclenché par les tests.

La TVA a été temporairement activée pour voir sa grille, sans créer de taux ; son réglage initial a été rétabli. Une zone de démonstration possède un forfait désactivé. Les données de démonstration sont locales : deux produits privés, un client fictif `example.invalid`, une commande impayée et un coupon clairement nommés. Le manifeste est conservé dans `output/woocommerce-review/fixtures.json` du workspace.

PHP/JS lint, contrats CSS, génération des tokens, 4096 couleurs personnalisées, audit des budgets et contrôle de dérive WooCommerce passent. Les dix dernières combinaisons ciblées (accueil, marketing, commande avec note, paiements, paiement à la livraison) ne remontent plus de défaut au dépistage. Les débordements des réglages généraux, de la livraison et de l’e-mail ont été revérifiés absents dans les deux thèmes. `baseline.json` correspond aux assets WooCommerce 11.2.1 installés.

## Environnement et preuves

Le PHP 8.2.29 initial a rencontré un abort Objective-C sous macOS pendant les appels réseau. Le service local utilise désormais PHP 8.2.35 ; les séries HTTP et la navigation d’administration ont ensuite fonctionné sans nouveau plantage observé. Cela ne certifie pas tous les redémarrages futurs de Local. Le serveur PHP temporaire utilisé pour isoler le diagnostic a été arrêté ; le navigateur de vérification reste ouvert.

Les captures, résultats JSON, scripts de parcours et patch se trouvent dans `output/woocommerce-review/` du workspace. La sauvegarde SQL est explicitement **après activation et avant configuration** : la première tentative d’export préactivation a échoué. Aucune restauration de base ni réinitialisation n’a été effectuée.
