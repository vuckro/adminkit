# Connecteurs natifs WordPress — 30 octobre 2026

Écran local `options-connectors.php`, WordPress 7.1.4. Correction dans `assets/css/wp-screens/wpds.css`, sans modifier WordPress ni installer un fournisseur.

Le nouveau fournisseur WPDS utilise `--wpds-color-background-*` et `--wpds-color-foreground-*`, appliqués inline sur la racine et des thèmes imbriqués. Le raccord historique ne reconnaissait que `--wpds-color-bg-*` / `fg-*` et l’ancien attribut de fournisseur. Cela laissait le cadre et l’en-tête clairs autour du contenu sombre.

Le raccord historique est conservé. Le nouveau raccord cible l’attribut sémantique `data-wpds-corner-radius` accompagné du marqueur de variable inline, sous `.boot-layout-container`. Les priorités `!important` remplacent exclusivement ces variables inline. Aucun nom de classe généré, compteur React ou moteur de thème JavaScript.

Les rôles AdminKit couvrent les surfaces, textes, bordures, accents, états désactivés et sémantiques. L’écran Connecteurs reçoit également un encart neutre, des boutons secondaires et un focus cohérents, le logo monochrome OpenAI lisible dans les deux thèmes, des descriptions et une aide contrastées, ainsi que le retour à la ligne sur mobile. Les logos couleur Anthropic/Google sont conservés.

Contrôles : 1280 et 390 px, clair/sombre, repos/survol/focus des boutons d’installation. Captures et dépistage textuel dans `output/funnelkit-review/followup/core-connectors-final.log`. Aucune installation ni connexion de service ; les parcours réseau d’installation ne sont donc pas testés. Les thèmes WPDS imbriqués de cet écran ont été vérifiés ; les autres applications utilisant WPDS doivent être reprises lors de leurs évolutions.
