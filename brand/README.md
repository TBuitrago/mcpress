# MCPress · Brand

**English** · [Español](README.es.md)

**MCPress** (by TBA Digitals · mcpress.tbadigitals.com) connects your AI to WordPress over MCP. The brand tells a single idea: **your AI runs WordPress, you set the rules.** The symbol is a key with an "m" on it; pressing it means deciding: approving a change or letting go of control in automatic mode.

> Full guide (tokens, voice, components and the live animated logo): the **MCPress** design system in Claude — https://claude.ai/artifact/CS5yfATi9jWdtJ6P7FAD2d

![MCPress](logo/animated/mcpress-logo-loop-light.svg)

## What's here

| Folder | Contents |
|---|---|
| `logo/svg/` | Symbol (light, dark, signal, one-colour), wordmark, horizontal and stacked lockups, with and without the "■ BY TBA DIGITALS" endorsement. Everything is outlined. |
| `logo/png/` | The lockups at 2400 px and the symbol at 1024 px, transparent background. |
| `logo/animated/` | Animated SVGs: `intro` (2 s, plays once), `loop` (3.6 s, continuous), `symbol-intro`, `symbol-loader`. They work inside `<img>` and respect `prefers-reduced-motion`. |
| `logo/animated/video/` | `mcpress-logo-loop-*.gif`, `mcpress-symbol-loader.gif`, `mcpress-logo-intro-*-1080p.mp4` and `mcpress-logo-intro-alpha.webm` (with transparency, for video editing). |
| `logo/animated/embed/` | HTML snippets ready to paste into the website: `header` (no endorsement, 44 px) and `hero` (with endorsement). Intro on load, and the key presses again on hover. |
| `wordpress-org/` | `icon-128x128.png`, `icon-256x256.png`, `icon.svg`, `banner-772x250.png`, `banner-1544x500.png` → these go in the `assets/` folder of the plugin's SVN. |
| `web/` | `favicon.svg` (switches to a cream key in dark mode), `favicon.ico`, PNG 16–64, `apple-touch-icon.png`, `icon-192/512.png`, `og-image.png` 1200×630. |
| `social/` | 800 px avatar (dark and light) and a Spanish-language banner, 1544×500. |
| `tokens.css` / `tokens.json` | Colours, typography, radii, shadows and motion curves. `tokens.css` includes the "key" button `.mcp-key-btn`. |
| `source/` | The generator: everything above comes from here. |

## The essentials

**Colours** (inherited from TBA Digitals). Token names in `tokens.css` / `tokens.json` are in Spanish.

| Token | Hex | Use |
|---|---|---|
| Ink (`--mcp-tinta`) | `#0D1A2E` | Key face, text, dark background |
| Paper (`--mcp-papel`) | `#F4F1EA` | Light background, key face on dark |
| Signal (`--mcp-senal`) | `#415BF7` | The key's lip, the primary action |
| Light signal (`--mcp-senal-clara`) | `#7B8DFF` | Signal on dark backgrounds, flash |
| Approved / Pending / Rejected (`--mcp-aprobado` / `--mcp-pendiente` / `--mcp-rechazado`) | `#16734A` / `#8F5500` / `#B42828` | Approval flow states (always with a word and an icon) |

**Typography:** Poppins ExtraBold for headlines and the wordmark; Poppins Regular for body text; IBM Plex Mono in UPPERCASE for eyebrows, preceded by ■.

**Writing:** "MCPress" in running text; lowercase "mcpress" only in the logo. Talk to the reader directly and informally ("you", not "the user"; in Spanish, *tú*). Buttons say the exact verb ("Approve and run", not "OK").

**Logo:** the `-light` version on light backgrounds, `-dark` on dark ones. Minimum clear space: half a key all around. Minimum sizes: symbol 16 px, horizontal 96 px wide, with endorsement 240 px. Don't recolour the key, don't change the "m", don't add shadows or gradients.

## The animated logo

The key drops (0–0.46 s), gets pressed (0.50 s), releases a blue ring (0.62 s) and the word "mcpress" comes out of it letter by letter (0.66–1.41 s); finally the TBA endorsement appears (1.30–1.86 s). It always ends on the static logo.

```html
<!-- In a README or any HTML -->
<img src="brand/logo/animated/mcpress-logo-loop-light.svg" alt="MCPress" width="420">

<!-- In the mcpress.tbadigitals.com header: paste the contents of -->
<!-- brand/logo/animated/embed/mcpress-logo-header-light.html -->
```

Rules: the intro plays once per page load; the loop never goes in the header; never two animated logos at once; with `prefers-reduced-motion` it stays still.

## Regenerating

```bash
cd brand/source
npm install                    # Poppins, IBM Plex Mono, Playwright
pip install fonttools
python3 build_brand.py ../     # static and animated SVGs, embeds, favicon.svg, tokens.css
python3 compose.py && node render.js ..   # PNGs: WordPress.org, web, social, logos
```

Change the key geometry, the wordmark tracking or the curves at the top of `build_brand.py`; everything else is recalculated.
