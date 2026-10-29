# MCPress

**English** · [Español](README.es.md)

![MCPress](brand/logo/animated/mcpress-logo-loop-light.svg)

**Your AI runs WordPress. You set the rules.**

Connect your AI to WordPress over MCP. You decide how much control it gets: prior approval or fully automatic.
A product by [TBA Digitals](https://tbadigitals.com) · [mcpress.tbadigitals.com](https://mcpress.tbadigitals.com)

## What it is

MCPress turns your WordPress site into an MCP (Model Context Protocol) server. Claude, ChatGPT, Cursor, VS Code or any MCP client can run the whole site: content, media, users, plugins, themes, menus, files, database, settings and any plugin with a REST API (WooCommerce, ACF, SEO…).

The MCP server runs **inside your WordPress**. There is no relay service in between: keys, approvals and the activity log stay on your site.

## Permission modes

Every connected AI has its own mode:

| Mode | Read | Write | Destructive | Critical |
|---|---|---|---|---|
| **Read only** | runs | refused | refused | refused |
| **Prior approval** | runs | asks you | asks you | asks you |
| **Automatic** | runs | runs | runs | asks you |
| **Full access** | runs | runs | runs | runs |

- *Critical* means irreversible or sensitive: running PHP, SQL writes, editing `wp-config.php`, creating administrators, permanent deletions, core updates…
- When something is waiting for your approval, it shows up in **wp-admin → MCPress → Approvals** (and in the admin bar). When you approve, exactly what the AI asked for runs and the AI gets the result.
- You can set per-tool rules (always run, always ask, never) or switch whole areas off.
- In every mode: the AI cannot touch MCPress' settings or keys, cannot lock you out, and everything is logged.

## Connect

- **Claude (claude.ai, Desktop) and ChatGPT:** add a custom connector with your site's MCP URL (`https://yoursite.com/wp-json/mcpress/v1/mcp`). You log in to WordPress, choose the mode and approve (OAuth 2.1).
- **Claude Code, Cursor, VS Code, Windsurf, Gemini CLI…:** create a key in **MCPress → Connect** and copy the ready-made snippet for your client.

## Languages

The plugin is written in English and ships a complete Spanish translation. WordPress picks the language from the site or user setting (**Settings → General → Site Language**, or **Profile → Language**): every Spanish locale (`es_CO`, `es_ES`, `es_MX`…) gets Spanish, everything else gets English.

## Repository layout

| Folder | Contents |
|---|---|
| `mcpress/` | The plugin (this is what gets installed). |
| `mcpress/tests/` | Unit tests (`php tests/unit.php`) and end-to-end tests with Docker (`bash tests/env.sh up && bash tests/env.sh e2e`). Not included in the zip. |
| `mcpress/bin/i18n.php` | Generates `languages/mcpress.pot` and compiles the Spanish translation. Not included in the zip. |
| `brand/` | Brand: logos, animations, banners, tokens and the generator behind them. |
| `.github/workflows/release.yml` | Builds `mcpress.zip` and attaches it to every GitHub release. |

## Releasing a version

1. Bump the version in `mcpress/mcpress.php` (the `Version:` header and `MCPRESS_VERSION`) and in `Stable tag:` in `mcpress/readme.txt`.
2. Commit and create a GitHub release with the tag `vX.Y.Z`. The release notes are the changelog shown in wp-admin.
3. The workflow attaches `mcpress.zip`. Every site running MCPress updates itself within a few hours (or right away with **Check GitHub now** in Settings).

For sites to read the releases, the repository must be public; if it is private, each site needs `define( 'MCPRESS_GITHUB_TOKEN', '…' );` in `wp-config.php`.

License: GPL-2.0-or-later
