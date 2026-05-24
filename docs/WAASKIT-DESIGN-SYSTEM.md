# WaasKit — Design System Couleurs

> **Document de référence — version définitive et verrouillée.**
> Ce document fait autorité pour la gestion des couleurs sur tous les projets WaasKit.
> Toute IA ou tout développeur intervenant sur un projet WaasKit doit s'y conformer
> sans exception. Il est conçu pour rester valable plusieurs années.

| | |
|---|---|
| **Version** | 1.0.0 |
| **Statut** | Verrouillé |
| **Périmètre** | Tokens de couleur |
| **Plateforme cible** | Bricks Builder (WordPress) |
| **Niveaux** | 2 — Primitif → Sémantique |
| **Total tokens sémantiques** | 23 |
| **Références externes** | Radix UI · shadcn/ui · GitHub Primer |

---

## Table des matières

1. [Contexte — un framework pour Bricks Builder](#1-contexte)
2. [Philosophie — la logique en deux niveaux](#2-philosophie)
3. [La règle d'or](#3-la-regle-dor)
4. [Primitif vs Sémantique — le test infaillible](#4-primitif-vs-semantique)
5. [Le lexique des suffixes](#5-lexique-des-suffixes)
6. [Niveau 1 — Primitif](#6-niveau-1-primitif)
7. [Niveau 2 — Sémantique : les 23 tokens](#7-niveau-2-semantique)
8. [Câblage Bricks Builder Theme Styles](#8-cablage-bricks)
9. [Composants décortiqués](#9-composants-decortiques)
10. [Cas d'usage — à faire / à ne pas faire](#10-cas-dusage)
11. [Inverser les couleurs — le module `.invert`](#11-inverser-les-couleurs)
12. [Anti-patterns — les pièges à éviter](#12-anti-patterns)
13. [Tokens futurs — évolution maîtrisée](#13-tokens-futurs)
14. [Récapitulatif](#14-recapitulatif)

---

## 1. Contexte

### Un framework conçu pour Bricks Builder

Ce système de couleurs n'est pas un système abstrait : il est **conçu spécifiquement
pour Bricks Builder**, le constructeur visuel WordPress utilisé sur tous les projets
WaasKit. Cela explique plusieurs de ses spécificités.

**Bricks gère trois choses qui influencent l'architecture :**

- **Les Color Palettes** (Bricks Settings → Colors). Chaque palette est un fichier
  JSON de couleurs. WaasKit en utilise cinq : `⚙️ Neutre`, `🎨 Marque`,
  `🔔 Notifications` (les trois palettes **primitives**), et `🏷️ Sémantique`
  (la palette **sémantique**).
- **Les CSS Variables** (Bricks Settings → Variables). Toutes les couleurs sont
  exposées comme variables CSS natives (`var(--accent)`), donc utilisables partout :
  dans Bricks, dans du CSS custom, dans des classes.
- **Le Theme Style** (Bricks → Theme Styles). C'est l'écran où l'on règle l'apparence
  globale des éléments : boutons, typographie, formulaires. **Dans le modèle WaasKit,
  le Theme Style joue le rôle de la "couche composant".** On ne crée donc pas de
  tokens par composant — Bricks s'en charge nativement.

**Conséquence directe sur l'architecture :** le système ne comporte que
**deux niveaux de tokens** (Primitif, Sémantique). La troisième couche, dite
"composant", n'existe pas sous forme de tokens — elle est déléguée au Theme Style
de Bricks. C'est un choix délibéré : il évite de doubler le travail et garde le
système à 23 tokens au lieu de plusieurs dizaines.

### Inspirations — Radix UI, shadcn/ui, GitHub Primer

Le système s'appuie sur les conventions de trois design systems de référence,
choisis pour leur rigueur et leur sobriété :

- **Radix UI** — pour le modèle de *rôles* (un token nomme un usage, pas une
  couleur) et pour l'idée d'échelles de couleur structurées par paliers.
- **shadcn/ui** — pour la sobriété du nommage (`--background`, `--foreground`,
  `--border`, `--input`...) et le principe d'un nombre volontairement réduit de
  tokens sémantiques.
- **GitHub Primer** — pour le vocabulaire des suffixes (`-subtle`, `-strong`,
  `-on`), aujourd'hui standard dans l'industrie.

Le système WaasKit n'est pas une copie de ces design systems : il en reprend les
principes éprouvés et les adapte aux contraintes de Bricks Builder et à la palette
de marque WaasKit (un jaune `#fed53e`).

---

## 2. Philosophie

### Deux niveaux, jamais mélangés

```
┌─────────────────────────────────────────────────────────────┐
│  NIVEAU 1 — PRIMITIF                                          │
│  Palettes : ⚙️ Neutre · 🎨 Marque · 🔔 Notifications          │
│  La réserve de couleurs brutes. Valeurs fixes (#fed53e).      │
│  → On n'utilise JAMAIS ces tokens directement dans une page.  │
└───────────────────────────┬───────────────────────────────────┘
                            │ chaque token sémantique
                            │ pointe vers un primitif
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  NIVEAU 2 — SÉMANTIQUE                                        │
│  Palette : 🏷️ Sémantique — 23 tokens                          │
│  Les rôles. Un usage, pas une couleur (--accent, --surface).  │
│  → C'est la SEULE couche que l'on utilise pour construire.    │
└───────────────────────────┬───────────────────────────────────┘
                            │ le Theme Style de Bricks
                            │ pointe ses réglages vers le sémantique
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  COUCHE COMPOSANT — déléguée à Bricks Theme Styles            │
│  Boutons, typographie, formulaires réglés une fois.           │
│  → Aucun token créé ici. Bricks gère nativement.              │
└─────────────────────────────────────────────────────────────┘
```

### Les trois principes fondateurs

**Principe 1 — Trois étages, jamais mélangés.**
Primitif alimente Sémantique. Sémantique alimente la couche composant (Theme Style).
Un composant lit *uniquement* du sémantique. Le sémantique lit *uniquement* du
primitif. **Aucun saut d'étage n'est autorisé.**

**Principe 2 — Le sémantique nomme un USAGE, pas une couleur.**
`--surface` ne veut pas dire "gris clair" : il veut dire "fond d'un bloc". Le jour
où ce fond devient beige, le nom reste vrai. C'est cette propriété qui rend le
système évolutif : **les noms ne mentent jamais.**

**Principe 3 — Toujours par paires fond / contenu.**
Chaque fois qu'une surface colorée existe, le token du contenu qui se pose dessus
existe aussi. `--accent` (le fond du bouton) va avec `--accent-on` (le texte du
bouton). Cette règle élimine les erreurs de contraste et rend l'inversion de
couleurs automatique.

---

## 3. La règle d'or

> ### Un composant — ou un réglage de Bricks Theme Styles — ne lit JAMAIS un primitif. Il lit TOUJOURS un token sémantique.

C'est l'unique règle à mémoriser. Tout le reste en découle.

**Le signal d'alerte.** Si vous vous surprenez à sélectionner une couleur de
l'étage Primitif — `--neutral-l-7`, `--primary`, `--error-l-9`, `#fed53e` — dans le
réglage d'un composant, d'une classe ou du Theme Style : **arrêtez-vous**. Cela
signifie qu'un token sémantique manque pour cet usage. La marche à suivre :

1. Identifier le rôle réel (ex. "fond de champ de recherche").
2. Vérifier qu'aucun token existant ne le couvre déjà.
3. Si le besoin est réel et récurrent, ajouter le token à la bonne section de
   la palette Sémantique, et incrémenter la version de ce document.
4. Utiliser le nouveau token sémantique — jamais le primitif en direct.

**Pourquoi cette règle est non négociable.** Elle garantit deux choses :
- **Le re-theming en une ligne.** Changer la couleur de marque d'un client =
  modifier `--accent`. Tout suit. Sans la règle, il faudrait retrouver chaque
  occurrence de `--primary` et juger au cas par cas.
- **L'inversion de couleurs automatique.** Le module `.invert` (section 11) ne
  fonctionne que parce que les composants lisent des noms sémantiques stables.

---

## 4. Primitif vs Sémantique

C'est la confusion la plus fréquente. Elle se règle définitivement avec un test
en trois questions.

| Question | Primitif 🎨 | Sémantique 🏷️ |
|---|---|---|
| Le nom décrit-il une **couleur** ou un **usage** ? | Une couleur ou une famille de couleurs (`primary`, `error`, `neutral`) | Un usage, un rôle (`accent`, `surface`, `border`) |
| Sa valeur est-elle une **vraie couleur** ou un **renvoi** ? | Une vraie couleur (`#fed53e`, `hsl(0,0%,18%)`) | Toujours un renvoi `var(--...)` vers un primitif |
| Un composant peut-il **taper dedans** ? | **Jamais** | **Toujours** |

### Le piège de vocabulaire — à lire attentivement

Des noms comme `error`, `success`, `primary` *sonnent* sémantiques (« erreur »,
« succès », « principal »). **Dans le système WaasKit, ce sont des primitifs.**
Ce sont les noms des *familles de couleurs* : `--primary` = la famille jaune de
marque, `--error` = la famille rouge, `--success` = la famille verte.

Ne vous fiez jamais au mot. Fiez-vous au test ci-dessus :
- `--error` a pour valeur `#f04662` (une vraie couleur) → **primitif**.
- `--accent` a pour valeur `var(--primary)` (un renvoi) → **sémantique**.

### Exemple concret sur un bouton

```
✅ CORRECT
Le bouton lit         →  var(--accent)        [sémantique]
--accent pointe vers  →  var(--primary)       [primitif]
--primary vaut        →  #fed53e              [valeur]
Conséquence : changer la marque = 1 seule ligne modifiée.

❌ INCORRECT
Le bouton lit         →  var(--primary)       [primitif — saut d'étage]
Conséquence : le composant est soudé au primitif. Changer la marque
oblige à retrouver chaque occurrence et à juger laquelle est "la marque".
```

---

## 5. Lexique des suffixes

Quatre suffixes, jamais d'autres. Ce lexique permet de **deviner** un token sans
le chercher. Il est repris de GitHub Primer et de Radix.

| Suffixe | Signification | Exemple | Origine |
|---|---|---|---|
| `-on` | Contenu (texte / icône) posé **SUR** une surface colorée | `--accent-on` | Material, Primer |
| `-subtle` | Version **pâle / atténuée** d'une couleur de rôle, utilisée en fond | `--accent-subtle`, `--error-subtle` | Primer, Radix |
| `-hover` | État de **survol** d'un rôle | `--accent-hover` | usage courant |
| `-strong` | Version **renforcée** d'un neutre | `--border-strong` | Primer |

**Règle :** ne jamais inventer un cinquième suffixe. Si un besoin semble exiger un
nouveau suffixe, c'est presque toujours qu'il faut repenser le token, pas étendre
le lexique.

---

## 6. Niveau 1 — Primitif

Le niveau primitif est **validé et figé**. Il est réparti en trois palettes Bricks.

### Palette `⚙️ Neutre`

Familles de gris, noir et blanc. Chaque famille dispose d'échelles complètes.

- `--neutral` — gris principal. **Flip automatique en dark mode** : la valeur claire
  s'inverse seule. C'est la propriété la plus importante du système (voir plus bas).
- `--black` / `--white` — noir et blanc, avec échelles.

### Palette `🎨 Marque`

- `--primary` — jaune de marque WaasKit (`#fed53e` en light).
- `--secondary` — orange (`#ff983e`).
- `--tertiary` — bleu (`#4d77fe`).

### Palette `🔔 Notifications`

- `--info` — bleu informatif (`#2895d4`).
- `--success` — vert (`#11b76b`).
- `--warning` — orange/jaune (`#ffa100`).
- `--error` — rouge (`#f04662`).

### Les stops d'échelle

Chaque famille décline trois types de variantes :

| Type | Notation | Description |
|---|---|---|
| Light | `l-1` à `l-10` | Variantes claires, de la plus claire (`l-10`... selon famille) aux paliers |
| Dark | `d-1` à `d-10` | Variantes foncées |
| Transparent | `t-1` à `t-10` | Variantes en transparence (alpha croissant) |

**Le flip dark mode du neutre.** La famille `--neutral` est configurée avec
`darkModeEnabled`. En mode sombre, `--neutral-l-1` (presque blanc en light) devient
automatiquement le ton sombre équivalent. **Conséquence pratique :** pour tous les
tokens sémantiques qui pointent vers du neutre, **un seul mapping suffit** — le
dark mode est gratuit. C'est pourquoi la majorité des tokens de la section
Sémantique n'ont pas de valeur `dark` explicite.

**Aucune intervention n'est nécessaire sur le niveau Primitif.** Il sert
exclusivement à alimenter les tokens sémantiques.

---

## 7. Niveau 2 — Sémantique

**23 tokens. Une seule palette Bricks (`🏷️ Sémantique`).**
L'ordre des sections suit l'ordre de construction d'une page : on pose d'abord les
fonds, puis les séparations, puis l'action, puis l'interaction, puis le feedback,
et le texte en dernier (car réglé une fois pour toutes dans le Theme Style).

Notation : la colonne « Dark » indique `flip` quand le primitif neutre s'inverse
automatiquement. Une valeur explicite n'apparaît que lorsqu'elle est réellement
nécessaire (cas des couleurs de marque et de notification, qui ne flippent pas).

### 7.1 — SURFACE · les plans de fond · 4 tokens

| Token | → Light | → Dark | Usage |
|---|---|---|---|
| `--background` | `--neutral-l-1` | flip | Fond global de la page |
| `--surface` | `--neutral-l-2` | flip | Carte, panel, section contrastée |
| `--elevated` | `--neutral-l-3` | flip | Plan flottant : modale, dropdown, tooltip, popover |
| `--overlay` | `--black-t-7` | `--black-t-7` | Voile sombre derrière une modale |

Trois niveaux de profondeur : `background` < `surface` < `elevated`, du plan le
plus enfoncé au plus proche de l'œil. **Ne jamais créer un quatrième niveau de
fond** : au-delà de trois, c'est l'ombre (`box-shadow`, voir `--shadow-*` dans les
variables Bricks) qui crée la hiérarchie, pas une nuance de fond supplémentaire.

`--overlay` est volontairement un noir transparent dans les deux modes : un voile
de modale doit assombrir, quel que soit le thème.

### 7.2 — BORDURE · séparation et structure · 2 tokens

| Token | → Light | → Dark | Usage |
|---|---|---|---|
| `--border` | `--neutral-l-4` | flip | Contour standard : carte, champ de formulaire, filet de séparation |
| `--border-strong` | `--neutral-l-5` | flip | Contour appuyé : champ au survol/focus, séparateur marqué |

Deux niveaux suffisent. Le filet de séparation (`divider`) n'a pas de token propre :
c'est un `--border`. Les champs de formulaire utilisent eux aussi `--border` au
repos et `--border-strong` au focus — il n'existe pas de token de bordure dédié
aux inputs (décision délibérée : éviter un token redondant).

### 7.3 — ACCENT · la marque en action · 4 tokens

| Token | → Light | → Dark | Usage |
|---|---|---|---|
| `--accent` | `--primary` | `--primary` | Bouton principal, lien, élément actif/sélectionné |
| `--accent-hover` | `--primary-d-1` | `--primary-l-1` | État survol de l'accent |
| `--accent-on` | `--primary-d-10` | `--primary-l-10` | Texte / icône posé SUR un fond `--accent` |
| `--accent-subtle` | `--primary-l-10` | `--primary-d-10` | Fond pâle teinté marque : section, badge |

**`--accent-hover`** assombrit en light (`d-1`) et éclaircit en dark (`l-1`).
Un survol doit toujours produire un retour visuel par contraste avec le fond :
sur fond clair on assombrit, sur fond sombre on éclaircit.

**`--accent-on`** est le contenu posé sur le bouton accent. La marque WaasKit est
un jaune clair : le texte dessus doit être **foncé** pour rester lisible. Le token
pointe vers un primitif marque très foncé (`--primary-d-10`), jamais vers un stop
clair.

**`--accent-subtle`** est un jaune **très pâle et opaque**. Usage principal dans
WaasKit : **fond de section ou de page légèrement teinté marque**. Usage secondaire :
fond de badge. ⚠️ Sur un badge, le texte posé dessus doit être `--accent-on`
(foncé), pas `--accent` (jaune vif sur jaune pâle = contraste insuffisant).

> **Note de cohérence avec les versions antérieures.** Dans les premières
> itérations du système, `--accent-subtle` pointait vers `--primary-t-1` (jaune
> *transparent*). La version finale utilise un jaune *opaque* (`--primary-l-10`).
> Un fond opaque est plus prévisible — il ne se mélange pas avec ce qu'il y a
> derrière. C'est l'approche de Radix pour ses couleurs « subtle ». Ce choix est
> définitif.

### 7.4 — STATE · interaction et formulaires · 2 tokens

| Token | → Light | → Dark | Usage |
|---|---|---|---|
| `--input` | `--neutral-l-1` | flip | Fond d'un champ de formulaire |
| `--focus` | `--primary` | `--primary` | Anneau de focus clavier (`outline`) |

**`--input`** mérite une explication, car sa valeur (`--neutral-l-1`) est
aujourd'hui identique à celle de `--background`. **Ce n'est pas un doublon : c'est
un point de réglage indépendant.** Avant ce token, le fond d'un champ de
formulaire était choisi au cas par cas (`surface` ? `elevated` ? `background` ?
selon le contexte). `--input` fixe ce choix une fois pour toutes. Le jour où l'on
veut des champs légèrement grisés pour les détacher du fond de page, on modifie
`--input` → `--neutral-l-2` sans toucher au fond de page. Le nom indépendant est
ce qui crée cette liberté.

**`--focus`** est l'anneau de focus clavier, **obligatoire pour l'accessibilité**
(WCAG / RGAA). Il doit être **opaque et contrasté** — un focus translucide est
quasi invisible et non conforme. Le token pointe vers `--primary` opaque. On le
fait respirer autour de l'élément avec `outline-offset`, **jamais** avec de la
transparence.

> ⚠️ **Point de vigilance technique.** Vérifier dans le fichier de palette que
> `--focus` pointe bien vers `var(--primary)` et **non** vers `var(--primary-t-5)`
> ou tout autre stop transparent. Un focus translucide est un défaut
> d'accessibilité. Valeur correcte attendue : `--focus → --primary`.

Les autres états d'interaction sont déjà couverts ailleurs : le **survol** des
éléments de marque par `--accent-hover` ; l'état **actif/sélectionné** et le
survol de carte par `--accent-subtle` ; l'état **désactivé** nativement par Bricks
(pseudo-classe `:disabled`).

### 7.5 — NOTIFICATION · les 4 états de feedback · 8 tokens

| Token | → Light | → Dark | Usage |
|---|---|---|---|
| `--success` | `--success` | `--success` | Icône, bordure, point de validation |
| `--success-subtle` | `--success-l-9` | `--success-d-9` | Fond pâle d'un bandeau succès |
| `--warning` | `--warning` | `--warning` | Icône, bordure d'avertissement |
| `--warning-subtle` | `--warning-l-9` | `--warning-d-9` | Fond pâle d'un bandeau attention |
| `--error` | `--error` | `--error` | Icône, bordure, message de champ invalide |
| `--error-subtle` | `--error-l-9` | `--error-d-9` | Fond pâle d'un bandeau erreur |
| `--info` | `--info` | `--info` | Icône, bordure d'information |
| `--info-subtle` | `--info-l-9` | `--info-d-9` | Fond pâle d'un bandeau info |

**Deux variantes par état.** Chaque état a sa couleur **vive** (icône, bordure,
texte du message) et son fond **pâle** (`-subtle`). Sur l'exemple d'une alerte
d'erreur : le fond du bandeau = `--error-subtle`, l'icône et le filet latéral =
`--error`, le texte du message = `--error` (le rouge vif reste lisible sur un fond
aussi pâle). Deux tokens, alerte complète, aucun troisième token nécessaire.

Les couleurs de notification ne flippent pas automatiquement (elles ne sont pas
neutres). Leur variante `-subtle` a donc une valeur `dark` explicite, qui pointe
vers le stop foncé `d-9` de la même famille — un fond sombre teinté, équivalent
sombre du fond pâle clair.

### 7.6 — TEXTE · contenu textuel · 3 tokens

| Token | → Light | → Dark | Usage |
|---|---|---|---|
| `--heading` | `--neutral-l-9` | flip | Titres `h1` à `h6` |
| `--text` | `--neutral-l-8` | flip | Corps de texte, paragraphes |
| `--text-muted` | `--neutral-l-7` | flip | Texte secondaire, légende, placeholder de champ |

Placée en dernier car cette section se câble **une seule fois** dans Bricks Theme
Styles (Typography), puis n'est plus touchée pendant la production.

**`--text-muted`** est structurellement nécessaire même s'il paraît peu utilisé :
c'est le **placeholder de chaque champ de formulaire** et la **légende de chaque
média**. Bricks Theme Styles possède un champ « placeholder » qui doit pointer
vers ce token. Sans lui, ce réglage retomberait sur un primitif en dur.

---

## 8. Câblage Bricks Theme Styles

Le Theme Style de Bricks **est** la couche composant du système. On le configure
**une seule fois** par projet, en pointant chaque réglage vers un token
**sémantique** — jamais primitif.

| Zone Bricks | Réglage | Token à utiliser |
|---|---|---|
| **Page** | Background | `--background` |
| **Typography** | Headings (h1–h6) | `--heading` |
| **Typography** | Text / body | `--text` |
| **Typography** | Link color | `--accent` |
| **Typography** | Link hover | `--accent-hover` |
| **Button — primary** | Background | `--accent` |
| **Button — primary** | Text | `--accent-on` |
| **Button — primary** | Background hover | `--accent-hover` |
| **Button — secondary** | Background | `--surface` |
| **Button — secondary** | Text | `--accent` |
| **Button — secondary** | Border | `--border` |
| **Form — input** | Background | `--input` |
| **Form — input** | Text | `--text` |
| **Form — input** | Placeholder | `--text-muted` |
| **Form — input** | Border | `--border` |
| **Form — input** | Border focus | `--border-strong` |
| **Form — input** | Outline / focus ring | `--focus` |

Une fois ce câblage fait, chaque élément Bricks posé dans une page hérite
automatiquement des bons tokens. On ne choisit plus jamais une couleur à la main.

---

## 9. Composants décortiqués

Pour chaque composant courant : le token exact de chaque propriété.

### Bouton principal
- **Fond** : `--accent`
- **Fond au survol** : `--accent-hover`
- **Texte / icône** : `--accent-on`
- *Jamais de blanc sur le fond jaune — toujours `--accent-on`.*

### Bouton secondaire
- **Fond** : `--surface`
- **Texte** : `--accent`
- **Bordure** : `--border`
- *L'accent reste le signal d'action ; il porte la couleur du texte, pas le fond.*

### Lien
- **Couleur** : `--accent`
- **Couleur au survol** : `--accent-hover`
- *Le lien se repère par sa couleur, pas seulement par un soulignement.*

### Carte / panel
- **Fond** : `--surface`
- **Titre** : `--heading`
- **Corps de texte** : `--text`
- **Bordure** : `--border`
- **Survol (optionnel)** : fond `--accent-subtle` ou bordure `--border-strong`

### Champ de formulaire (input)
- **Fond** : `--input`
- **Texte saisi** : `--text`
- **Placeholder** : `--text-muted`
- **Bordure au repos** : `--border`
- **Bordure au focus** : `--border-strong`
- **Anneau de focus** : `--focus`

### Notification / alerte (exemple : erreur)
- **Fond du bandeau** : `--error-subtle`
- **Icône** : `--error`
- **Filet latéral** : `--error`
- **Texte du message** : `--error`
- *Décliner à l'identique pour `success`, `warning`, `info`.*

### Modale
- **Fond de la modale** : `--elevated`
- **Voile arrière** : `--overlay`
- **Titre** : `--heading`
- **Corps** : `--text`
- **Bordure** : `--border`

### Dropdown / tooltip / popover
- **Fond** : `--elevated`
- **Texte** : `--text`
- **Bordure** : `--border`

### Badge
- **Fond** : `--accent-subtle`
- **Texte** : `--accent-on`
- *Le badge est teinté par la marque ; ce n'est pas l'action elle-même.*

### Élément désactivé
- **Fond** : `--surface`
- **Texte** : `--text-muted`
- **Bordure** : `--border`
- *Ou laisser Bricks gérer l'état `:disabled` nativement.*

### Section de page teintée marque
- **Fond de section** : `--accent-subtle`
- **Titre / texte** : `--heading` / `--text` (restent lisibles sur le jaune pâle)

---

## 10. Cas d'usage — à faire / à ne pas faire

### Bouton principal

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond | `--accent` | `--primary` — saut d'étage vers le primitif |
| Survol | `--accent-hover` | `--primary-l-2` — éclaircit en light, l'élément s'efface |
| Texte | `--accent-on` | `#ffffff` ou `--white` — blanc illisible sur le jaune |

### Bouton secondaire

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond | `--surface` | `--accent-subtle` — réservé aux sections/badges |
| Texte | `--accent` | `--text` — le bouton perd son signal d'action |
| Bordure | `--border` | une valeur de couleur en dur |

### Carte / panel

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond | `--surface` | `--background` — la carte ne se détache plus du fond |
| Titre | `--heading` | `--text` — hiérarchie typographique perdue |
| Corps | `--text` | `--heading` — tout paraît en gras, lecture pénible |
| Survol | `--accent-subtle` ou `--border-strong` | créer un token `--hover` dédié — inutile |

### Champ de formulaire

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond | `--input` — stable et identique partout | alterner `surface`/`elevated`/`background` selon le contexte |
| Texte saisi | `--text` | `--text-muted` — le texte saisi paraît désactivé |
| Placeholder | `--text-muted` | `--text` — le placeholder ne se distingue plus du texte réel |
| Bordure repos | `--border` | `--border-strong` — trop appuyé au repos |
| Bordure focus | `--border-strong` + `--focus` | aucun retour de focus — non accessible |

### Notification / alerte

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond du bandeau | `--error-subtle` (pâle, lisible) | `--error` — fond rouge vif, agressif |
| Icône | `--error` | `--error-subtle` — icône invisible |
| Filet latéral | `--error` | `--border` — perd le signal d'erreur |
| Texte | `--error` ou `--text` | `--error-subtle` — texte illisible |

### Modale

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond | `--elevated` — plan le plus haut | `--surface` — ne se distingue pas d'une carte |
| Voile | `--overlay` | aucun voile — perte du focus visuel |
| Titre / corps | `--heading` / `--text` | couleurs en dur |

### Badge

| Propriété | ✅ À faire | ❌ À ne pas faire |
|---|---|---|
| Fond | `--accent-subtle` | `--accent` — bloc jaune vif trop présent |
| Texte | `--accent-on` | `--accent` — jaune vif sur jaune pâle, contraste faible |

---

## 11. Inverser les couleurs — le module `.invert`

### Le problème à résoudre

Sur une page claire, on veut parfois une section sombre (footer, bloc CTA, bandeau
contrasté). Sur une page sombre, parfois une section claire. La tentation serait
de créer des tokens dédiés : `--surface-inverse`, `--text-inverse`,
`--heading-inverse`, etc. **C'est une mauvaise approche** : elle double une grande
partie du système et crée une dette de maintenance permanente.

### La solution — un scope, pas des tokens

La bonne approche reprend le principe du dark mode CSS natif (`color-scheme`) et
de la philosophie de Radix : **on ne crée pas de tokens inversés, on crée un
contexte qui réassigne les tokens existants.**

Une **classe de scope** est posée sur la section concernée. À l'intérieur de cette
classe, on **réécrit localement** la valeur des tokens de base (Surface, Bordure,
Texte) pour qu'ils pointent vers les stops opposés. Tous les éléments enfants —
boutons, cartes, champs, textes — suivent automatiquement, **sans aucune
modification**, parce qu'ils lisent toujours les mêmes noms de tokens.

C'est exactement le mécanisme du dark mode global, mais restreint au périmètre
d'une section.

### Le nom de la classe — recommandation

Le nom `.invert` est correct mais ambigu : « inverser » par rapport à quoi ?
Pour un système qui doit durer, un nommage **explicite par cible** est plus
robuste et plus lisible. Recommandation pour WaasKit :

| Classe | Effet | Quand l'utiliser |
|---|---|---|
| `.scheme-dark` | Force le rendu sombre de la section | Section sombre dans une page claire |
| `.scheme-light` | Force le rendu clair de la section | Section claire dans une page sombre |

Deux classes explicites valent mieux qu'un `.invert` unique : le nom dit ce qui
va se passer, indépendamment du thème de la page. Cette approche est calquée sur
la logique `color-scheme: light | dark` du CSS standard. C'est la recommandation
définitive ; `.invert` reste acceptable en solution de repli si une seule classe
est préférée.

### Mise en œuvre (CSS custom dans Bricks)

```css
/* Section forcée en rendu sombre, quel que soit le thème de la page */
.scheme-dark {
  --background:     var(--neutral-d-1);
  --surface:        var(--neutral-d-2);
  --elevated:       var(--neutral-d-3);
  --border:         var(--neutral-d-4);
  --border-strong:  var(--neutral-d-5);
  --heading:        var(--neutral-d-9);
  --text:           var(--neutral-d-8);
  --text-muted:     var(--neutral-d-7);
  --input:          var(--neutral-d-1);
}

/* Section forcée en rendu clair, quel que soit le thème de la page */
.scheme-light {
  --background:     var(--neutral-l-1);
  --surface:        var(--neutral-l-2);
  --elevated:       var(--neutral-l-3);
  --border:         var(--neutral-l-4);
  --border-strong:  var(--neutral-l-5);
  --heading:        var(--neutral-l-9);
  --text:           var(--neutral-l-8);
  --text-muted:     var(--neutral-l-7);
  --input:          var(--neutral-l-1);
}
```

### Ce que le module couvre — et ne couvre pas

| Cas | Couvert ? |
|---|---|
| Section sombre dans une page claire | ✅ Oui |
| Section claire dans une page sombre | ✅ Oui |
| Footer foncé, bloc CTA contrasté, bandeau inversé | ✅ Oui |
| Un troisième thème (ni clair ni sombre, ex. « bleu nuit ») | ❌ Non — créer une classe `.scheme-x` supplémentaire sur le même mécanisme, uniquement si un projet le requiert |

Le module couvre environ **99 % des cas réels**. Le 1 % restant a une voie de
sortie propre (une classe de scope supplémentaire), sans jamais toucher aux 23
tokens de base.

### Pourquoi ne pas créer de tokens inversés — comparaison

| Approche scope (✅ retenue) | Approche tokens inversés (❌ rejetée) |
|---|---|
| Une classe réécrit ~9 tokens de base | Crée `--surface-inverse`, `--text-inverse`... pour chaque token |
| Le système reste à 23 tokens | Le système gonfle à 40+ tokens |
| Fonctionne dans les deux sens | Demande des tokens distincts par sens |
| Tout composant suit sans modification | Chaque composant doit savoir s'il est « normal » ou « inversé » |

---

## 12. Anti-patterns — les pièges à éviter

**❌ Sauter un étage.**
Utiliser `var(--neutral-l-7)` ou `var(--primary)` directement dans un composant,
une classe ou le Theme Style. → Toujours passer par un token sémantique.

**❌ Nommer un token par sa couleur.**
Créer `--yellow-button`, `--gray-card`. → Réintroduit la confusion que le système
élimine. Un token se nomme par son usage.

**❌ Créer un token de composant.**
Créer `--card-bg`, `--navbar-bg`, `--header-color`. → C'est le rôle du Theme Style
de Bricks. Un token de composant est un doublon, donc une dette.

**❌ Ajouter un quatrième niveau de surface.**
→ Au-delà de trois fonds, c'est l'ombre (`box-shadow`, variables `--shadow-*`) qui
crée la profondeur.

**❌ Créer un token utilisé deux ou trois fois.**
→ Un token naît d'un besoin réel, fréquent et constaté. Jamais par anticipation.
Un token rare alourdit le système sans bénéfice.

**❌ Mettre une couleur en dur.**
`#ffffff`, `rgb(0,0,0)`, `hsl(...)` dans un composant. → Aucune valeur brute dans
la couche de construction. Toujours un token.

**❌ Focus translucide.**
`--focus` pointant vers un stop transparent (`--primary-t-5`...). → Non conforme à
l'accessibilité. Le focus est opaque et contrasté ; on l'espace avec
`outline-offset`.

**❌ Doubler le système pour le dark mode ou les sections inversées.**
Créer `--xxx-inverse` ou `--xxx-dark`. → Utiliser le flip natif du neutre et le
module de scope `.scheme-*`.

**❌ Utiliser `--accent-subtle` comme fond de bouton.**
→ `--accent-subtle` est un fond de section/badge. Un bouton principal utilise
`--accent`, un bouton secondaire utilise `--surface`.

---

## 13. Tokens futurs — évolution maîtrisée

À considérer **uniquement** quand un besoin réel, fréquent et constaté apparaît.
Ne jamais créer par anticipation. Ces éléments ne font **pas** partie du système
verrouillé v1.0.0.

| Élément envisagé | Quand l'ajouter | Priorité |
|---|---|---|
| Tokens sémantiques d'ombre (`--shadow-card`, `--shadow-modal`...) | Les variables `--shadow-xs`...`--shadow-xl` existent déjà côté Bricks. Une couche sémantique d'ombre serait le prochain chantier logique : c'est l'ombre, pas la couleur, qui distingue une modale d'une carte. | Haute |
| `--accent-2` (+ `-hover`, `-on`, `-subtle`) | Pour un client à marque bi-couleur. La famille `--secondary` (orange) est déjà prête en primitif ; il suffirait d'un mapping. | Moyenne |
| `--success-on` / `--warning-on` / `--error-on` / `--info-on` | Uniquement si des bandeaux de notification à **fond vif** (texte clair sur couleur pleine) sont introduits. Le choix actuel `-subtle` ne le requiert pas. | Basse |
| `.scheme-x` (classe de scope) | Pour un troisième thème de couleur sur un projet spécifique. Même mécanisme que `.scheme-dark` / `.scheme-light`. | Basse |

**Principe directeur pour toute évolution :** un token sémantique naît seulement
d'un besoin réel, fréquent et constaté. Cette retenue est ce qui garde le système
lisible et maintenable dans la durée.

---

## 14. Récapitulatif

### Les 23 tokens sémantiques

| # | Section | Token | → Light | → Dark |
|---|---|---|---|---|
| 1 | Surface | `--background` | `--neutral-l-1` | flip |
| 2 | Surface | `--surface` | `--neutral-l-2` | flip |
| 3 | Surface | `--elevated` | `--neutral-l-3` | flip |
| 4 | Surface | `--overlay` | `--black-t-7` | `--black-t-7` |
| 5 | Bordure | `--border` | `--neutral-l-4` | flip |
| 6 | Bordure | `--border-strong` | `--neutral-l-5` | flip |
| 7 | Accent | `--accent` | `--primary` | `--primary` |
| 8 | Accent | `--accent-hover` | `--primary-d-1` | `--primary-l-1` |
| 9 | Accent | `--accent-on` | `--primary-d-10` | `--primary-l-10` |
| 10 | Accent | `--accent-subtle` | `--primary-l-10` | `--primary-d-10` |
| 11 | State | `--input` | `--neutral-l-1` | flip |
| 12 | State | `--focus` | `--primary` | `--primary` |
| 13 | Notification | `--success` | `--success` | `--success` |
| 14 | Notification | `--success-subtle` | `--success-l-9` | `--success-d-9` |
| 15 | Notification | `--warning` | `--warning` | `--warning` |
| 16 | Notification | `--warning-subtle` | `--warning-l-9` | `--warning-d-9` |
| 17 | Notification | `--error` | `--error` | `--error` |
| 18 | Notification | `--error-subtle` | `--error-l-9` | `--error-d-9` |
| 19 | Notification | `--info` | `--info` | `--info` |
| 20 | Notification | `--info-subtle` | `--info-l-9` | `--info-d-9` |
| 21 | Texte | `--heading` | `--neutral-l-9` | flip |
| 22 | Texte | `--text` | `--neutral-l-8` | flip |
| 23 | Texte | `--text-muted` | `--neutral-l-7` | flip |

### Synthèse

- **2 niveaux** : Primitif (3 palettes Bricks) → Sémantique (1 palette, 23 tokens).
- **Couche composant** déléguée au Theme Style de Bricks.
- **1 règle d'or** : un composant ne lit jamais un primitif, toujours un sémantique.
- **4 suffixes** : `-on`, `-subtle`, `-hover`, `-strong`. Jamais d'autres.
- **Dark mode** : gratuit sur les neutres (flip), explicite sur marque et
  notification.
- **Sections inversées** : module de scope `.scheme-dark` / `.scheme-light`,
  zéro token ajouté.
- **Références** : Radix UI, shadcn/ui, GitHub Primer.

### Point de vigilance avant mise en production

Vérifier dans la palette `🏷️ Sémantique` que `--focus` pointe vers `var(--primary)`
(opaque) et non vers un stop transparent. C'est le seul écart possible identifié
entre la documentation et les fichiers de palette.

---

*Document maintenu dans le dépôt WaasKit Design System. Toute modification du
périmètre des tokens doit être versionnée et reportée dans ce document.*

**Fin du document — WaasKit Design System Couleurs v1.0.0**
