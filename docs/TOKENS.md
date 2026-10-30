# Tokens AdminKit

Le système actuel est décrit dans [WAASKIT-DESIGN-SYSTEM.md](WAASKIT-DESIGN-SYSTEM.md). Les anciennes palettes Bricks ne sont plus la source du thème par défaut.

## Chaîne de génération

1. `tokens/palettes/waaskit.json` : instantané des variables WaasKit natives, avec provenance.
2. `php tokens/build.php` : génère `assets/css/waaskit-tokens.css` ; `--check` détecte toute dérive des trois feuilles générées. Le build suit les dépendances dans les deux thèmes et refuse les références manquantes ou circulaires. Il n’émet que les variables requises et leurs dépendances ; le relevé amont complet reste conservé.
3. `tokens/adminkit.json` définit les rôles `--ak-*` ; `tokens/wordpress.css` contient la correspondance WordPress. Le build génère `assets/css/tokens.css` sans analyser ni réécrire une feuille CSS existante.
4. Une couleur personnalisée ou la source Bricks explicitement sélectionnée peut ajouter une surcharge d’accent. Le défaut est le preset monochrome WaasKit, qui inverse ses rôles avec le thème. Le bleu est explicite (`adminkit`, valeur historique).

| AdminKit | WaasKit |
| --- | --- |
| `--ak-bg` | `--wk-bg` |
| `--ak-surface`, `--ak-input-bg` | `--wk-surface` |
| `--ak-elevated` | `--wk-surface-raised` |
| `--ak-surface-muted` | `--wk-surface-sunken` |
| `--ak-text`, `--ak-heading` | `--wk-text` |
| `--ak-text-muted` | `--wk-text-muted` |
| `--ak-border`, `--ak-border-strong` | `--wk-border`, `--wk-border-strong` |
| `--ak-primary-text` | `--wk-accent-text` |
| `--ak-primary`, `--ak-primary-hover` | `--wk-accent`, `--wk-accent-hover` |
| `--ak-primary-subtle`, `--ak-on-accent` | `--wk-accent-soft`, `--wk-on-accent` |
| `--ak-focus` | `--wk-focus-ring` |
| `--ak-success`, `--ak-warning`, `--ak-error`, `--ak-info` | `--wk-success-text`, `--wk-warning-text`, `--wk-danger-text`, `--wk-info-text` |
| statut `-subtle` | statut `-soft` |
| `--ak-font-body`, `--ak-font-mono` | `--wk-font-sans`, `--wk-font-mono` |
| rayons `s/m/l/xl/full` | rayons `md/lg/xl/2xl/full` |
| `--ak-shadow-elevated`, `--ak-shadow-subtle` | `--wk-elevation-2`, `--wk-elevation-1` |
| `--ak-duration-fast`, `--ak-ease-standard` | `--wk-duration-fast`, `--wk-ease-standard` |

Sur le site public, `waaskit-toolbar-tokens.css` est généré avec les mêmes variables dans le seul périmètre `#wpadminbar` ; le thème public ne reçoit aucune déclaration WaasKit sur `:root`. L’aperçu des réglages possède ses propres rôles clair/sombre, issus de la même source.

Les alias se recalculent sur le même élément racine lorsque `data-adminkit-theme` change. Les variables générées n’imposent ni `prefers-color-scheme` ni préférence différente de celle d’AdminKit. Les contrôles natifs conservent leur densité WordPress ; les couleurs, polices et géométries suivent WaasKit.

Le pont `wp-screens/wpds.css` couvre aussi les noms étendus `background` et
`foreground` des pages admin-ui de l’éditeur de site. Ces valeurs natives étant
définies en ligne, les rôles concernés les remplacent explicitement sur le
provider, en desktop et mobile, sans cibler les classes générées de WordPress.

## Contrat de cascade

- Les composants ne redéfinissent pas la palette globale : fonds `--ak-primary`, texte sur fond `--ak-on-accent`, liens et icônes d’accent `--ak-primary-text`.
- `tokens/appearance.css` possède toute la famille personnalisée, avec des sélecteurs explicites `data-adminkit-accent="custom"`. PHP et l’aperçu JS fournissent uniquement la couleur, les canaux sRGB et les encres de contraste ; aucune famille CSS ne doit être dupliquée dans ces deux langages.
- Changer de preset neutralise immédiatement les valeurs personnalisées encore présentes dans une balise inline. Le thème et le preset sont indépendants, y compris dans l’aperçu et le canvas Gutenberg.
- Le fond de survol personnalisé et son texte ont leur propre paire de contraste ; les alias RGB WordPress suivent le même preset.
- Les règles `.widefat:not(.wp-list-table)` possèdent les tableaux génériques ; `.wp-list-table` possède les listes natives. Le chrome global ne remet pas leurs bordures à zéro.

`php dev/css-contract.php` bloque la réutilisation des aplats comme texte d’accent et le retour des collisions de propriété des tableaux. Les garde-fous sont complétés par les tests navigateur des deux thèmes et des palettes.
