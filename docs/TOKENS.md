# Tokens AdminKit

Le système actuel est décrit dans [WAASKIT-DESIGN-SYSTEM.md](WAASKIT-DESIGN-SYSTEM.md). Les anciennes palettes Bricks ne sont plus la source du thème par défaut.

## Chaîne de génération

1. `tokens/palettes/waaskit.json` : instantané des variables WaasKit natives, avec provenance.
2. `php tokens/build.php` : génère `assets/css/waaskit-tokens.css` ; `--check` détecte toute dérive.
3. `assets/css/tokens.css` : relie les rôles `--ak-*` consommés par les écrans à `--wk-*`.
4. Une couleur personnalisée ou la source Bricks explicitement sélectionnée peut ajouter une surcharge d’accent. Le mode par défaut conserve les rôles WaasKit dans les deux thèmes.

| AdminKit | WaasKit |
| --- | --- |
| `--ak-bg` | `--wk-bg` |
| `--ak-surface`, `--ak-input-bg` | `--wk-surface` |
| `--ak-elevated` | `--wk-surface-raised` |
| `--ak-surface-muted` | `--wk-surface-sunken` |
| `--ak-text`, `--ak-heading` | `--wk-text` |
| `--ak-text-muted` | `--wk-text-muted` |
| `--ak-border`, `--ak-border-strong` | `--wk-border`, `--wk-border-strong` |
| `--ak-primary`, `--ak-primary-hover` | `--wk-accent`, `--wk-accent-hover` |
| `--ak-primary-subtle`, `--ak-on-accent` | `--wk-accent-soft`, `--wk-on-accent` |
| `--ak-focus` | `--wk-focus-ring` |
| `--ak-success`, `--ak-warning`, `--ak-error`, `--ak-info` | `--wk-success-text`, `--wk-warning-text`, `--wk-danger-text`, `--wk-info-text` |
| statut `-subtle` | statut `-soft` |
| `--ak-font-body`, `--ak-font-mono` | `--wk-font-sans`, `--wk-font-mono` |
| rayons `s/m/l/xl/full` | rayons `md/lg/xl/2xl/full` |
| `--ak-shadow-elevated` | `--wk-elevation-2` |

Sur le site public, `waaskit-toolbar-tokens.css` est généré avec les mêmes variables dans le seul périmètre `#wpadminbar` ; le thème public ne reçoit aucune déclaration WaasKit sur `:root`. L’aperçu des réglages possède ses propres rôles clair/sombre, issus de la même source.

Les alias se recalculent sur le même élément racine lorsque `data-adminkit-theme` change. Les variables générées n’imposent ni `prefers-color-scheme` ni préférence différente de celle d’AdminKit. Les contrôles natifs conservent leur densité WordPress ; les couleurs, polices et géométries suivent WaasKit.
