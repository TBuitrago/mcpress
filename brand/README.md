# MCPress · Marca

**MCPress** (by TBA Digitals · mcpress.tbadigitals.com) conecta tu IA con WordPress por MCP. La marca cuenta una sola idea: **tu IA propone, tú presionas aprobar.** El símbolo es una tecla con la "m"; presionarla es aprobar.

> Guía completa (tokens, voz, componentes y logo animado en vivo): sistema de diseño **MCPress** en Claude — https://claude.ai/artifact/CS5yfATi9jWdtJ6P7FAD2d

![MCPress](logo/animated/mcpress-logo-loop-light.svg)

## Qué hay aquí

| Carpeta | Contenido |
|---|---|
| `logo/svg/` | Símbolo (claro, oscuro, señal, una tinta), wordmark, lockups horizontal y apilado, con y sin firma "■ BY TBA DIGITALS". Todo contorneado. |
| `logo/png/` | Los lockups a 2400 px y el símbolo a 1024 px, fondo transparente. |
| `logo/animated/` | SVG animados: `intro` (2 s, una vez), `loop` (3,6 s, continuo), `symbol-intro`, `symbol-loader`. Funcionan dentro de `<img>` y respetan `prefers-reduced-motion`. |
| `logo/animated/video/` | `mcpress-logo-loop-*.gif`, `mcpress-symbol-loader.gif`, `mcpress-logo-intro-*-1080p.mp4` y `mcpress-logo-intro-alpha.webm` (con transparencia, para editar video). |
| `logo/animated/embed/` | Fragmentos HTML listos para pegar en la web: `header` (sin firma, 44 px) y `hero` (con firma). Intro al cargar y la tecla se vuelve a presionar al pasar el cursor. |
| `wordpress-org/` | `icon-128x128.png`, `icon-256x256.png`, `icon.svg`, `banner-772x250.png`, `banner-1544x500.png` → van en la carpeta `assets/` del SVN del plugin. |
| `web/` | `favicon.svg` (cambia a tecla crema en modo oscuro), `favicon.ico`, PNG 16–64, `apple-touch-icon.png`, `icon-192/512.png`, `og-image.png` 1200×630. |
| `social/` | Avatar 800 px (oscuro y claro) y banner en español 1544×500. |
| `tokens.css` / `tokens.json` | Colores, tipografía, radios, sombras y curvas de movimiento. `tokens.css` incluye el botón "tecla" `.mcp-key-btn`. |
| `source/` | El generador: todo lo anterior sale de aquí. |

## Lo esencial

**Colores** (heredados de TBA Digitals)

| Token | Hex | Uso |
|---|---|---|
| Tinta | `#0D1A2E` | Cara de la tecla, texto, fondo oscuro |
| Papel | `#F4F1EA` | Fondo claro, cara de la tecla sobre oscuro |
| Señal | `#415BF7` | El labio de la tecla, la acción principal |
| Señal clara | `#7B8DFF` | Señal sobre fondos oscuros, destello |
| Aprobado / Pendiente / Rechazado | `#16734A` / `#8F5500` / `#B42828` | Estados del flujo de aprobación (siempre con palabra e icono) |

**Tipografía:** Poppins ExtraBold para titulares y el wordmark; Poppins Regular para texto; IBM Plex Mono en MAYÚSCULAS para antetítulos, precedidos de ■.

**Escritura:** "MCPress" en texto corrido; "mcpress" en minúsculas solo en el logo. Tutea. Los botones dicen el verbo exacto ("Aprobar desinstalación", no "OK").

**Logo:** versión `-light` sobre fondos claros, `-dark` sobre oscuros. Área libre mínima: media tecla alrededor. Tamaños mínimos: símbolo 16 px, horizontal 96 px de ancho, con firma 240 px. No recolorees la tecla, no cambies la "m", no añadas sombras ni degradados.

## El logo animado

La tecla cae (0–0,46 s), se presiona (0,50 s), suelta un anillo azul (0,62 s) y la palabra "mcpress" sale de ella letra a letra (0,66–1,41 s); al final aparece la firma de TBA (1,30–1,86 s). Termina siempre en el logo estático.

```html
<!-- En un README o cualquier HTML -->
<img src="brand/logo/animated/mcpress-logo-loop-light.svg" alt="MCPress" width="420">

<!-- En el header de mcpress.tbadigitals.com: pega el contenido de -->
<!-- brand/logo/animated/embed/mcpress-logo-header-light.html -->
```

Reglas: la intro una vez por carga; el bucle nunca en el header; nunca dos logos animados a la vez; con `prefers-reduced-motion` se ve quieto.

## Regenerar

```bash
cd brand/source
npm install                    # Poppins, IBM Plex Mono, Playwright
pip install fonttools
python3 build_brand.py ../     # SVG estáticos, animados, embeds, favicon.svg, tokens.css
python3 compose.py && node render.js ..   # PNG: WordPress.org, web, redes, logos
```

Cambia la geometría de la tecla, el tracking del wordmark o las curvas al inicio de `build_brand.py`; todo lo demás se recalcula.
