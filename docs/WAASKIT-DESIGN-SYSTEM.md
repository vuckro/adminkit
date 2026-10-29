# WaasKit — système actuel

AdminKit utilise le design system publié sur [Design WaasKit](https://design.waaskit.site/), relevé le 29 octobre 2026. La source précise est [l’export CSS natif](https://design.waaskit.site/wp-content/uploads/waaskit-exports/wk-ui.css). Les composants marketing de cet export ne sont pas chargés dans WordPress.

Le fichier `tokens/palettes/waaskit.json` conserve la provenance et son SHA-256, les 74 variables stables, 82 variables contextuelles claires et 47 substitutions sombres. Le build ne distribue que les 50 variables utilisées et leurs dépendances ; le relevé complet reste dans Git. Il remplace les anciennes palettes Bricks jaune/orange. Le bleu par défaut est le rôle WaasKit `--wk-accent`, différent en clair et sombre, sans substitution par le bleu WordPress historique. Le site waaskit.com utilise une variante monochrome ; la référence retenue ici est Design WaasKit, chromaticité 0.17.

- Couleurs OKLCH, surfaces et textes sémantiques distincts.
- Geist et Geist Mono, hébergées localement avec leurs licences SIL OFL.
- Rayons, ombres et focus issus des variables `--wk-*`.
- Thème piloté par `data-adminkit-theme` ; aucun nouveau mécanisme de thème.
- Les composants existants consomment toujours `--ak-*` : leurs fonctions et le modèle WordPress restent identiques.
- La densité des contrôles natifs reste compacte (12/13/14 px) ; les grands espacements du site public ne sont pas imposés à wp-admin.

Les champs utilisent la bordure forte WaasKit. Le focus clavier utilise les deux anneaux du système. Les couleurs de statut consommées comme texte utilisent les rôles `*-text`, leurs fonds utilisent `*-soft`.

Le choix de couleur personnalisée reste disponible et ne remplace que sa famille d’accent. La source Bricks est une option explicite de compatibilité ; elle ne définit plus le thème par défaut.

## Mise à jour

Modifier la source JSON, puis exécuter :

```sh
php tokens/build.php
php tokens/build.php --check
```

Les fichiers `assets/css/waaskit-tokens.css` (administration et aperçu) et `assets/css/waaskit-toolbar-tokens.css` (barre publique uniquement) sont générés et ne doivent jamais être édités directement. `assets/css/tokens.css` est également généré, depuis `tokens/adminkit.json` (rôles et densité) et `tokens/wordpress.css` (correspondance WordPress). Les polices sont déclarées dans `assets/css/fonts.css`.
