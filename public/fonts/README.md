# Fonts

Self-hosted webfonts, served from `/fonts/`. All three families are licensed under
the SIL Open Font License 1.1, which permits bundling and serving them here.

| File | Family | Weights | Subset | Source |
|---|---|---|---|---|
| `Inter-latin.woff2`, `Inter-latin-ext.woff2` | Inter (Rasmus Andersson) | 400–700 variable | latin, latin-ext | fonts.gstatic.com, Inter v20 |
| `PlusJakartaSans-latin.woff2`, `PlusJakartaSans-latin-ext.woff2` | Plus Jakarta Sans (Tokotype) | 600–800 variable | latin, latin-ext | fonts.gstatic.com, v12 |
| `Fraunces-latin.woff2`, `Fraunces-latin-ext.woff2` | Fraunces (Undercase Type) — display serif for titles | 500–700 variable (opsz 9–144) | latin, latin-ext | fonts.gstatic.com, v38 |

The `@font-face` rules and `unicode-range`s are in `resources/css/app.css`.
The latin-ext subset covers ɛ and ɔ, which Ghanaian names need.
