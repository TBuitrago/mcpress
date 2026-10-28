=== MCPress ===
Contributors: tbadigitals
Tags: mcp, ai, claude, chatgpt, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your AI runs WordPress. You set the rules: prior approval, automatic, or full access.

== Description ==

MCPress turns your WordPress site into an MCP (Model Context Protocol) server, so Claude, ChatGPT, Cursor, VS Code or any MCP client can manage it: content, media, users, plugins, themes, menus, files, database, settings and any plugin that has a REST API (WooCommerce, ACF, SEO plugins…).

The MCP server runs inside your WordPress. There is no third-party relay: keys, approvals and the activity log stay on your site.

= You decide how much control your AI has =

Every connection has its own mode:

* **Read only** – the AI can look, not touch.
* **Prior approval** – reads run immediately; every change waits for you in wp-admin → MCPress → Approvals. When you approve, exactly the requested action runs and the AI gets the result.
* **Automatic** – changes run on their own; only critical actions (running PHP, SQL writes, editing wp-config.php, creating administrators, permanent deletions, core updates…) wait for you.
* **Full access** – nothing is asked. Everything is still logged.

Per-tool rules (always run / always ask / never) and switching whole areas off are in Settings.

= Safety nets =

* Hard limits in every mode: the AI cannot change MCPress' own settings or keys, cannot lock you out (the connected user and the last administrator are protected), and cannot deactivate MCPress.
* Sensitive data protection: password hashes, API keys and similar secrets are masked; wp-config.php and .env cannot be read.
* File changes are backed up first, PHP is syntax-checked, and after a PHP or .htaccess change the site is probed and rolled back automatically if it breaks.
* Activity log of every call, whatever the outcome.
* `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` are respected. `MCPRESS_DISABLE_PHP_EXECUTION` removes the PHP tool.

= Connect =

* **Claude (claude.ai, Desktop) and ChatGPT:** add a custom connector with your site's MCP URL. You log in to WordPress, choose the mode and approve (OAuth 2.1 with PKCE).
* **Claude Code, Cursor, VS Code, Windsurf, Gemini CLI…:** create an API key in MCPress → Connect and copy the ready-made snippet.

= Updates =

MCPress updates itself from its GitHub releases (it never comes from WordPress.org, so no other plugin can replace it). Automatic updates can be switched off in Settings.

== Installation ==

1. Upload the `mcpress` folder to `/wp-content/plugins/` or install the zip from Plugins → Add New → Upload.
2. Activate it. You land on MCPress → Connect.
3. Copy the MCP URL into your AI client, or create an API key.

== Frequently Asked Questions ==

= Does my content go through a third-party server? =

Not through MCPress: your AI client talks to your site directly. Your AI provider (Anthropic, OpenAI…) does see what the AI reads, as with any AI tool.

= What happens if I deactivate MCPress? =

The MCP endpoint disappears and every key stops working at once. Uninstalling deletes all MCPress data (define MCPRESS_KEEP_DATA_ON_UNINSTALL to keep it).

= My host strips the Authorization header =

MCPress → Diagnostics tells you. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to .htaccess, or send the key in the `X-MCPress-Key` header.

== Changelog ==

= 0.1.0 =
* First release: native MCP server (Streamable HTTP), four permission modes, approval queue, activity log, OAuth 2.1, ~95 tools, GitHub updates.
