# MCPress

![MCPress](brand/logo/animated/mcpress-logo-loop-light.svg)

**Tu IA maneja WordPress. Tú pones las reglas.**

Conecta tu IA con WordPress por MCP. Tú decides cuánto control le das: con tu aprobación previa o en automático.
Un producto de [TBA Digitals](https://tbadigitals.com) · [mcpress.tbadigitals.com](https://mcpress.tbadigitals.com)

## Qué es

MCPress convierte tu sitio WordPress en un servidor MCP (Model Context Protocol). Claude, ChatGPT, Cursor, VS Code o cualquier cliente MCP pueden manejar el sitio completo: contenido, medios, usuarios, plugins, temas, menús, archivos, base de datos, ajustes y cualquier plugin con API REST (WooCommerce, ACF, SEO…).

El servidor MCP corre **dentro de tu WordPress**. No hay un servicio intermedio: las claves, las aprobaciones y el registro de actividad se quedan en tu sitio.

## Modos de permiso

Cada IA conectada tiene su propio modo:

| Modo | Lectura | Escritura | Destructivo | Crítico |
|---|---|---|---|---|
| **Solo lectura** | se ejecuta | bloqueada | bloqueada | bloqueada |
| **Aprobación previa** | se ejecuta | te pregunta | te pregunta | te pregunta |
| **Automático** | se ejecuta | se ejecuta | se ejecuta | te pregunta |
| **Acceso total** | se ejecuta | se ejecuta | se ejecuta | se ejecuta |

- *Crítico* es lo irreversible o sensible: ejecutar PHP, SQL de escritura, editar `wp-config.php`, crear administradores, borrar para siempre, actualizar el núcleo…
- Cuando algo espera tu aprobación, aparece en **wp-admin → MCPress → Aprobaciones** (y en la barra de administración). Al aprobar, se ejecuta exactamente lo que la IA pidió y ella recibe el resultado.
- Puedes fijar reglas por herramienta (siempre ejecutar, siempre preguntar, nunca) o apagar áreas completas.
- En todos los modos: la IA no puede tocar los ajustes ni las claves de MCPress, no puede dejarte sin acceso y todo queda registrado.

## Conectar

- **Claude (claude.ai, Desktop) y ChatGPT:** agrega un conector personalizado con la URL MCP de tu sitio (`https://tusitio.com/wp-json/mcpress/v1/mcp`). Inicias sesión en WordPress, eliges el modo y apruebas (OAuth 2.1).
- **Claude Code, Cursor, VS Code, Windsurf, Gemini CLI…:** crea una clave en **MCPress → Conectar** y copia el fragmento listo para tu cliente.

## Estructura del repositorio

| Carpeta | Contenido |
|---|---|
| `mcpress/` | El plugin (es lo que se instala). |
| `mcpress/tests/` | Pruebas unitarias (`php tests/unit.php`) y de punta a punta con Docker (`bash tests/env.sh up && bash tests/env.sh e2e`). No van en el zip. |
| `mcpress/bin/i18n.php` | Genera `languages/mcpress.pot` y compila la traducción al español. No va en el zip. |
| `brand/` | Marca: logos, animaciones, banners, tokens y su generador. |
| `.github/workflows/release.yml` | Arma `mcpress.zip` y lo adjunta a cada release de GitHub. |

## Publicar una versión

1. Sube la versión en `mcpress/mcpress.php` (cabecera `Version:` y `MCPRESS_VERSION`) y en `Stable tag:` de `mcpress/readme.txt`.
2. Haz commit y crea un release en GitHub con el tag `vX.Y.Z`. Las notas del release son el registro de cambios que se ve en wp-admin.
3. El workflow adjunta `mcpress.zip`. Cada sitio con MCPress se actualiza solo en unas horas (o al pulsar **Revisar GitHub ahora** en Ajustes).

Para que los sitios puedan leer los releases, el repositorio debe ser público; si es privado, cada sitio necesita `define( 'MCPRESS_GITHUB_TOKEN', '…' );` en `wp-config.php`.

Licencia: GPL-2.0-or-later
