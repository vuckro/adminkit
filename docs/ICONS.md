# Icônes AdminKit

La bibliothèque embarquée est Lucide Static 1.64.0 : 2 275 SVG, sous les licences
ISC/MIT fournies dans `assets/icons/LUCIDE-LICENSE`. La provenance et la version
sont dans `assets/icons/lucide-version.json`. Le catalogue local est
`assets/icons/lucide.json` ; aucun CDN ni service tiers n’est appelé à l’exécution.
Source : https://lucide.dev/guide/static et paquet npm `lucide-static`.

`AdminKit_Icons` conserve ses méthodes publiques et ses anciens noms de rôles
(`article`, `document`, `chat`, etc.). Ces alias pointent maintenant vers les
contours Lucide : les configurations enregistrées restent valides. Les icônes
utilisent une grille de 24 px, un trait de 1,75 et des extrémités arrondies.
Les masques CSS utilisent le même SVG que les rendus en ligne.

Le menu, la barre d’outils, les actions WordPress courantes, les tris, les vues
médias et le tableau de bord utilisent le registre partagé. Les logos de marque
restent des logos. Les variantes explicites d’icônes personnalisées restent
prioritaires ; les filtres `adminkit/menu_icons` et `adminkit/toolbar_icons` sont
conservés.

Le sélecteur propose le catalogue complet avec une recherche par nom et des
équivalents français courants. Il crée 120 boutons à la fois et ajoute la suite
au défilement. La recherche sans résultat affiche un message traduit. Échap
ferme le sélecteur et rend le focus au bouton d’origine. La palette native
WordPress ne propose pas les glyphes remappés, pour que son aperçu reste fidèle.
Le catalogue SVG complet est inclus uniquement dans les données de la page Menu.

Les rôles `--ak-icon` et `--ak-icon-active` modèrent le contraste décoratif et
ses états. Les champs utilisent `--ak-input-border` au repos ; le focus conserve
l’accent et l’anneau WaasKit. La sélection du menu utilise une teinte à 8 % et
un repère de 2 px. La palette publique WaasKit n’est pas modifiée.

Vérification : `php dev/check-icons.php` valide tous les noms, les anciens alias,
la géométrie en contour et l’absence de balises/attributs exécutables dans le SVG.
Les captures et scripts de revue locale sont dans `output/` du dossier Adminkit.

Les icônes Lucide font partie de l’habillage par défaut, indépendamment de
l’activation du gestionnaire de menus. Le réglage interne
`replace_icons_enabled` reste disponible pour les intégrateurs. Les choix
par entrée du gestionnaire restent prioritaires, dont `wp-default` pour
rétablir le pictogramme WordPress d’origine sans désactiver toute la bibliothèque.
