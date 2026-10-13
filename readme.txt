=== WPMMCC ATS ===
Contributors: wpmmcc
Tags: multilingual, translation, multisite, virtual-site
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free multilingual translation manager: scan plugin fields, build translation rules, and manually translate posts in WordPress.

== Description ==

WPMMCC ATS is a multilingual content translation manager for WordPress. It scans your installed plugins to discover translatable fields, builds translation rules, and provides a manual translation editor in the WordPress admin.

**All features are free and fully functional.** No license key, trial period, or payment is required to use any feature of this plugin.

= Project links =

* Website: https://www.wpmm.cc/
* Documentation: https://www.wpmm.cc/docs/
* Getting started: https://www.wpmm.cc/docs/getting-started.html
* Manual translation: https://www.wpmm.cc/docs/manual-translation.html
* Automatic translation: https://www.wpmm.cc/docs/automatic-translation.html
* FAQ and privacy: https://www.wpmm.cc/docs/faq.html
* This plugin on WordPress.org: https://wordpress.org/plugins/wpmmcc-ats/
* Plugin source: https://github.com/wpmmcc/wpmmcc-ats
* Optional WPTSALL Client source: https://github.com/wpmmcc/wptsall-client
* Client releases: https://github.com/wpmmcc/wptsall-client/releases
* Client guide: https://www.wpmm.cc/docs/client-guide.html

= Key Features =

* **Automatic Field Discovery** — Scans WordPress post types, taxonomies, custom fields, and third-party plugin data (WooCommerce, Elementor, Yoast SEO, ACF, and more) to find all translatable content.
* **Translation Rule Engine** — Classifies fields into translate, sync, ID-mapping, compute, and skip categories automatically.
* **Manual Translation Editor** — Two-panel editor in wp-admin: source content on the left, translation input on the right.
* **Site relations** — Link a source language to a target language. A virtual site gets its own URL prefix (for example `/en_us/`). A network subsite can be the target as well.
* **Translation Memory** — Remembers previous translations to suggest matches for repeated content.
* **Gettext/String Translation** — Translates plugin and theme UI strings via the gettext filter.
* **Client API** — Optional. An administrator can let the separate WPTSALL Client call this site, collect source text, and write translations back. This plugin does not call a translation vendor.

= Optional: Standalone Translation Client =

This plugin can expose a REST API on **your own WordPress site** so an optional standalone translation client (a separate program you install) can pull work and push translations back. The plugin does **not** call www.wpmm.cc or any other remote host for this flow. Communication is only between the client and your site.

* Client source: https://github.com/wpmmcc/wptsall-client
* Client releases: https://github.com/wpmmcc/wptsall-client/releases
* Client guide: https://www.wpmm.cc/docs/client-guide.html
* The client's local web UI listens on 127.0.0.1:8977 by default (loopback only, on the machine where you run the client). The Desktop app listens on 127.0.0.1:8978 and stays on this computer. Port 8977 is unregistered and uncommon, so conflicts are rare; if another program has taken it, the WPTSALL_WEB_UI_PORT environment variable selects a different port for the web UI. The same client can also pair with the separate WPMMCC sync plugin. A direct WPMMCC pairing only syncs. When the client pairs those sites, sync_and_translate translates the title, content, and excerpt on the client before the push. WPMMCC does not call the translation vendor.

The client is NOT required. All plugin features work for manual translation without it.

== Installation ==

1. In wp-admin go to Plugins → Add New and search for "WPMMCC ATS" (or upload the ZIP via Plugins → Add New → Upload Plugin), then click Activate.
2. Open the WPTSALL menu in wp-admin. The built-in setup wizard walks you through the initial configuration: content languages, a scan of your content plugins, and your first site relation.
3. Translate posts in the manual translation editor, entirely inside wp-admin — no external service is needed.
4. (Optional) For automatic translation, enable the Client API under Task Management → Authorization, install the companion WPTSALL Client, and connect it with the generated client token.

When you configure the optional client with a third-party translation provider, **the client** (not this plugin) may send content you choose to translate to that provider under your own API keys and account terms.

= Updating =

Updates arrive through the standard WordPress updater (Dashboard → Updates, or the Plugins page) — no manual steps are required. Updating never touches your translation data: tables, virtual sites, translation memory, terminology and settings all carry over to the new version. See the Changelog section for what each release changes.

= Uninstalling =

Deactivate the plugin on the Plugins page, then delete it. Since 2.1.3, deleting the plugin keeps your translation data by default (tables, translated posts/terms, translation memory, terminology, language packs), so a reinstall restores everything — see the FAQ below. For a full cleanup instead, enable "Delete data on uninstall" in Settings before deleting; plugin settings, transients and scheduled tasks are always removed either way.

== Frequently Asked Questions ==

= Is this plugin free? =

Yes. All features in this plugin are free and fully functional. There are no locked features, no trial periods, and no license keys required.

= Do I need the translation client? =

No. The translation client is an optional separate application for automated translation. The plugin works fully on its own for manual translation.

= What is the client API token? =

The client API token is an authentication credential used by the optional translation client to communicate with this plugin's REST API. It is automatically generated and is NOT a license key.

= Does the slug "wpmmcc-ats" imply affiliation with WordPress.org? =

No. The slug was assigned for this product. The "wp" segment is part of the vendor brand **wpmmcc** (https://www.wpmm.cc), not a claim of affiliation with the WordPress Foundation or WordPress.org.

= Can I use this with Polylang or WPML? =

This plugin can coexist with Polylang and WPML. However, it provides its own independent multilingual system. You do not need Polylang or WPML to use this plugin.

= Can I use this together with the WPMMCC sync plugin? =

Yes. Both plugins can be installed on the same site, and they stay separate. WPMMCC ATS translates over its own REST channel (wptsall/v2) and stores its own translations, mappings, translation memory, and progress. WPMMCC copies content over a separate channel (wpmmcc/v1). A direct WPMMCC pairing only syncs. When the WPTSALL Client pairs those sites, sync_and_translate translates the title, content, and excerpt before the push. The ATS Translation Dashboard counts only the ATS channel.

= Where do plugin interface translations come from? =

English is built in. This plugin's strings are registered with the WordPress.org translation system (translate.wordpress.org): once the plugin is listed, language packs (starting with Simplified Chinese) install automatically from there when your site language is set under Settings → General. The GitHub source distribution additionally bundles a complete zh_CN translation in its languages/ directory. The plugin never downloads language packs from a third-party server.

= Does this plugin call Google, DeepL, OpenAI, or another translation API? =

No. This plugin has no translation-vendor API key and does not call those services. WordPress.org language packs for the plugin interface, once the plugin is listed, are installed by WordPress itself from translate.wordpress.org. They are not downloaded by this plugin from wpmm.cc.

If you install the optional WPTSALL Client and configure a translation provider there, the client sends the source text for the tasks it claimed to that provider, using the account and API key you entered in the client. That provider's terms and privacy policy apply to that call. The key stays in the client, on the computer where you run it. This plugin does not receive it.

= What data stays on this site? =

Site relations, translation rules, translated posts and terms, translation memory, terminology, language-pack entries, and the client token are stored in this site's database and uploads. They are not sent to wpmm.cc. The optional client receives source content only when it calls this site and an administrator has enabled the Client API. See External services for each route.

= Does the plugin write debug logs? =

No. Debug logging is disabled by default and all features work without any logging. A developer can enable file logging by defining WPTSALL_LOG_ENABLED as true in wp-config.php (minimum level and channel filters: WPTSALL_LOG_LEVEL, WPTSALL_LOG_CHANNELS). Logs are then written to wp-content/uploads/wptsall-logs-<hash>/ — a directory whose suffix is derived from your site's security salts so its name cannot be guessed from outside (WPTSALL_LOG_DIR overrides the location; legacy wptsall-logs/ is moved there automatically) — protected from web access. Credentials are automatically redacted before anything is written, and files older than 30 days are pruned automatically.

= What happens to my data when I delete the plugin? =

Since 2.1.3, deleting the plugin keeps your translation data (tables, virtual posts/terms, translation memory, terminology, language packs) by default, so a reinstall restores everything. Enable "Delete data on uninstall" in Settings if you want a full cleanup instead. Plugin settings, transients and scheduled tasks are always removed either way.

== Screenshots ==

1. Models — scan installed plugins and manage translation rules. Nothing is scanned until you start a scan.
2. Languages — content languages used by site relations.
3. Sites — virtual sites and network subsites.
4. Manual Translation — translate posts inside wp-admin. The client is not required.
5. Tasks — connect the optional WPTSALL Client. There is no license key.
6. Settings — URL form, the client API switch, and logging. Uninstall keeps translation data unless you opt in to delete it.

== External services ==

This plugin does **not** contact www.wpmm.cc, wpmm.cc, or any other third-party remote server by itself. There is no hard-coded outbound HTTP request from the plugin to WPMMCC servers (language packs, license checks, telemetry, or similar).

The only optional network communication is between **your WordPress site** and an **optional third-party program** (the standalone translation client) that **you** install and run. In that case the client calls REST endpoints hosted on **your site**; the plugin only answers those requests. The plugin never initiates a connection to the client or to www.wpmm.cc.

= Optional translation client (third-party program) =

What it is and what it is used for:
An optional self-hosted client can automate translation. When you enable the Client API in plugin settings and configure the client with your site URL, route secret, and client token, the client uses WordPress REST API routes under:

`/wp-json/wptsall/v2/{route_secret}/client/...`

`{route_secret}` is a site-generated path segment that obscures the client routes. All client routes require the header `X-WPTSALL-Client-Token` (token generated in wp-admin). Optional headers used by some routes: `X-WPTSALL-Protocol-Version`, `X-Client-Version`, `Idempotency-Key`, and (media upload only) media metadata headers listed below.

Communication happens only when:
1. An administrator enables the Client API and has a client token, AND
2. The optional client is running and configured to call this WordPress site.

What is **not** done by this plugin:
* No requests to www.wpmm.cc / wpmm.cc.
* No download of language packs from any remote host.
* No transmission of site content to WPMMCC or any translation service.
* This plugin does not call Google, DeepL, OpenAI, or any other translation API. If you configure such a provider in the optional client, that program sends the claimed source text to the endpoint you chose, with the API key you stored in the client. The provider's own terms of service and privacy policy apply to that request. This plugin does not store that API key.

Documentation for the optional client (not called by this plugin):
* Client guide: https://www.wpmm.cc/docs/client-guide.html
* FAQ and privacy: https://www.wpmm.cc/docs/faq.html
* Client source: https://github.com/wpmmcc/wptsall-client

= Client REST API: data sent and received =

Base path: `/wp-json/wptsall/v2/{route_secret}/client`

Auth on every route below (unless noted): request header `X-WPTSALL-Client-Token` (and standard WordPress REST request metadata such as User-Agent / Accept).

1. GET `/ping`
   * Client sends: auth header only.
   * Plugin responds: JSON `{ success, data: { plugin_version, encryption_supported, sync_execution_mode, local_executor_enabled } }`.

2. GET `/validate-token`
   * Client sends: auth header only.
   * Plugin responds: JSON `{ success, data: { site_url, site_domain, plugin_version, site_status, max_relations, token_status, encryption_supported, active_relations_count, sync_execution_mode, wordpress_version } }`.

3. GET `/site-relations`
   * Client sends: auth header only.
   * Plugin responds: JSON list of active site relations, including relation id, source/target site ids, source/target languages, status, linked models (model id, plugin slug/name, post types, taxonomies), and i18n-related relation config flags used for discovery.

4. GET `/rules`
   * Client sends: auth header; query args `relation_id` and/or `model_id` (at least one required); optional header `X-Client-Version`.
   * Plugin responds: JSON translation rules for the models (field keys, translate/map/skip capabilities, related taxonomies, content_format where applicable), or an error if the client version is unsupported / relation inactive.

5. GET `/content`
   * Client sends: auth header; query args `relation_id` (required), `data_type` (post|term|language_pack|option), `subtype`, `include_resync`, `page`, `per_page`, optional `include_ids` (comma-separated object ids).
   * Plugin responds: JSON page of items needing translation. Each item typically includes object type/id, subtype, and `complete_data` (source post/term field values, or language-pack entry fields such as msgid/msgctxt/reference/text_domain, depending on data_type), plus totals/pagination.

6. POST `/content/claim`
   * Client sends: auth header; JSON body `{ relation_id, data_type, items: [ { object_id, post_type|taxonomy|subtype, ... } ] }` (for language packs, items identify entry ids).
   * Plugin responds: JSON `{ success, claimed_count, claimed_items }` (or error). Marks items claimed so other workers avoid double work.

7. POST `/translation-callback`
   * Client sends: auth header; optional `Idempotency-Key`; JSON body including at least:
     * Common: `client_task_id`, `relation_id`, `business_line` (e.g. post_content, taxonomy_content, custom_model, theme_i18n, plugin_i18n).
     * Content lines: `object_type`, `object_id`, `source_lang`, `target_lang`, `translated_fields`, `translated_meta`, optional `media_mappings`.
     * i18n lines: `entries` array of `{ entry_id, msgstr }` (and related entry fields as provided by the client).
   * Plugin responds: JSON success/error, including idempotent replay when the same client_task_id or Idempotency-Key was already processed; may include result id / counts. On success, translated values are stored in WordPress (posts/terms/meta/template entries) and sync write-back may run according to site settings.

8. POST `/media-upload`
   * Client sends: auth header; headers `X-WPTSALL-Filename` (required), optional `X-WPTSALL-Task-ID`, `X-WPTSALL-Source-ID`, `X-WPTSALL-Relation-ID`; raw binary body of the media file (allowed media MIME types only; executable/script types rejected).
   * Plugin responds: JSON success with new attachment id / mapping info, or an error. Creates a WordPress media attachment on this site and may record a media mapping for later URL rewrite.

9. GET `/tasks`
   * Client sends: auth header; query args `status` (pending|retry), `limit`.
   * Plugin responds: JSON list of claimable task records for the client worker (task id, type/business context, language pair, payload/meta needed to perform the task, lease/claim fields). Claiming updates task status on the site.

= Summary of direction =

* **Client → Plugin (this WordPress site):** authentication token; discovery query parameters; claim bodies; translation results; media binaries.
* **Plugin → Client:** only HTTP responses to those requests (site/relation metadata, rules, source content to translate, task queue data, success/error). The plugin does not push data to the client unsolicited and does not call www.wpmm.cc.

== Privacy Policy ==

This plugin does not collect or store personal user data on external servers. All plugin data stays in your WordPress database on your hosting.

This plugin does not contact www.wpmm.cc. It does not download language packs from external servers.

If you use the optional translation client, that program talks only to **your** WordPress site via the Client API above. Any call from the client to a third-party translation provider is configured by you in the client, not by this plugin.

Optional product documentation (not called by this plugin):
* FAQ and privacy: https://www.wpmm.cc/docs/faq.html
* Client guide: https://www.wpmm.cc/docs/client-guide.html
* Client source: https://github.com/wpmmcc/wptsall-client

== Credits ==

This plugin bundles no third-party libraries. It is plain PHP built on WordPress core APIs (REST, WPDB/dbDelta, cron, gettext), and its admin pages use vanilla JavaScript plus jQuery as shipped with WordPress core.

Interface translations use the WordPress.org translation system (translate.wordpress.org, GlotPress).

For field discovery the plugin reads data from third-party content plugins — WooCommerce, Elementor, ACF, Yoast SEO and more. Those projects are not bundled or modified. The plugin coexists with the multilingual plugins WPML and Polylang; no code from either project is used.

== Changelog ==

= 2.1.10 =
* Changed: Directory listing describes the current free translation plugin, with screenshots of the WPTSALL admin screens and links to https://www.wpmm.cc/ and the WPTSALL Client on GitHub.
* Changed: The WPMMCC pairing note says a direct pairing only syncs, and the WPTSALL Client can translate the title, content, and excerpt while syncing.

= 2.1.9 =
* Changed: Release version is 2.1.9, the same number as the WPMMCC sync plugin.
* Changed: A normal file update from 2.1.4 applies database migrations on the next load, without deactivating the plugin.

= 2.1.4 =
* Added: Companion client uninstallers (Linux, macOS, Windows) remove the service, shortcuts and PATH entries cleanly; client data is kept by default and removed only with an explicit purge option.
* Added: Debug log files open with a metadata header (versions, environment, timezone) and every line carries a millisecond, explicitly-UTC timestamp.
* Added: Daily debug log files rotate at a size cap (5 MB by default, adjustable via the `wptsall_log_max_file_size` filter) and keep three backups instead of growing without bound.
* Fixed: Repeated table-creation passes within a single request (plugin activation, admin self-heal, module load) no longer re-run schema DDL and existence checks.
* Fixed (companion client): log rotation on Windows no longer breaks permanently when a viewer or antivirus tool holds the log file open; the log viewer shows millisecond timestamps.

= 2.1.3 =
* Changed: Deleting the plugin now keeps translation data by default; enable "Delete data on uninstall" in Settings for a full cleanup (settings, transients and scheduled tasks are always removed).
* Added: Multisite support for new and deleted subsites — new subsites get their data tables automatically, and deleted subsites have their tables cleaned up.
* Fixed: The debug log directory now carries a suffix derived from the site's security salts so its name cannot be guessed from outside; existing log folders are migrated automatically.
* Fixed: Migration version checks now compare against the running plugin version instead of stale hardcoded values.
* Fixed (companion client): runtime, database and log files now live in the OS data directory instead of the folder the program was launched from, on Windows/macOS/Linux.
* Fixed (companion client): self-update on Windows now replaces the running binary safely (rename-aside with rollback), and updates restart the service reliably; the WebUI only exits when a service manager can bring it back.
* Fixed (companion client): credential values are redacted at the logging boundary, and the log viewer parses structured log lines correctly.
* Maintained: Plugin Check reports 0 ERROR and 0 SQL findings; remaining warnings are trademark terms only.

= 2.1.2 =
* Unified the release version across the plugin and the optional companion client.
* Renamed the internal client-authorization module to client pairing (no feature or data change; legacy settings are migrated and cleaned up automatically on upgrade).
* Added clearer client installer pre-flight errors when the install directory is not writable (INSTALL_DIR_NOT_WRITABLE).
* Updated the bundled Simplified Chinese (zh_CN) translation and the multilingual README set.
* Maintained: Plugin Check reports 0 ERROR and 0 SQL findings; remaining warnings are trademark terms only.

= 2.1.1 =
* Added: Bundled Simplified Chinese (zh_CN) translation (1,790 strings), loaded automatically on Chinese sites.
* Added: Multilingual readme/ directory (16 languages) with a language index at the top of the main README.
* Fixed (WordPress.org review): closed the remaining 34 dynamic-SQL sites with literal branching, inline arguments, and %i identifier bindings.
* Fixed: model field-rules editor script enqueued instead of inline output; input sanitization inlined at 8 call sites; WP_PLUGIN_DIR uses replaced with plugin resolution helpers.
* Maintained: Plugin Check reports 0 ERROR and 0 SQL findings; remaining warnings are trademark terms only.

= 2.1.0 =
* Added relation-scoped, device-owned claim leases for options, content mappings, language packs, site strings, and durable outbox callbacks.
* Added idempotent option sync state migration and owner-aware callback/write-back lifecycle checks.

= 2.0.0 =
* Fixed (WordPress.org review): Unsafe SQL — all dynamic queries use $wpdb->prepare() with %i table/column identifiers; DDL uses a prepare-safe alter helper; integer IN lists use %d placeholders.
* Fixed (WordPress.org review): Plugin bootstrap renamed to wpmmcc-ats.php to match the plugin slug and Text Domain (wpmmcc-ats).
* Fixed: Plugin Check on slug folder wpmmcc-ats reports 0 ERROR and 0 SQL findings (remaining warnings are trademark term "wp" in name/slug only).
* Maintained: No outbound language-pack downloads; optional client REST documented in External services / Privacy sections.

= 1.9.1 =

* Fixed (WordPress.org review): Removed all remote language-pack download code and hard-coded calls related to wpmm.cc language packs; plugin makes no outbound requests to www.wpmm.cc.
* Fixed (WordPress.org review): Plugin zip no longer ships compiled language packs (.po/.mo); interface translations rely on translate.wordpress.org.
* Fixed (WordPress.org review): Documented optional third-party translation client communication in readme — full Client REST API list with request (sent) and response (received) data for each endpoint.
* Fixed (WordPress.org review): SQL safety — dynamic table/column identifiers use $wpdb->prepare() with %i placeholders; dynamic WHERE/IN lists use prepared values; Plugin Check reports zero PreparedSQL / UnescapedDB / NotPrepared issues.
* Fixed: Plugin Check DirectDatabaseQuery / SlowDBQuery warnings addressed for custom-table access patterns.
* Fixed: Cron manual trigger uses allowlisted literal hook names (no dynamic do_action variable).
* Fixed: Admin task-list GET filter reads clarified as non-mutating (nonce guidance).
* Fixed: Field discovery distinct-value queries use identifier placeholders (no string-interpolated column/table names).

= 1.9.0 =
* Fixed: WordPress.org review — path helpers, cron allowlist, late escaping, no unprefixed buddypress CPT
* Fixed: REST /site/verify nonce gate; langpack AJAX nonce; SQL prepare + migration policy; Plugin Check ERROR=0
* Fixed: No trialware gating; free Client API token is not a license key
* Fixed: hreflang_emitter form value aligned to wpmmcc-ats; legacy wptsall normalized
* Fixed: hreflang_emitter=yoast fully defers emission when Yoast is active
* Fixed: Quick Edit language meta requires edit_post capability per post
* Fixed: Regenerated zh_CN translations; removed stale Pro/License msgids
* Fixed: Widget option sync allowlist limited to core widgets (not all widget_*)
* Fixed: Langpack API prefers WPTSALL_LANGPACK_API_BASE; License_* class aliases documented
* Docs: Trademark FAQ; wporg-assets placeholders for directory banner/icon

= 1.8.0 =
* Fixed: Removed code-format field translation support; code/script meta keys are blocked in Field Discovery and sync
* Fixed: Language packs are written only to wp-content/uploads/wpmmcc-ats/languages/ (no WP_LANG_DIR writes)
* Fixed: Client translation callback rejects code content_format and code-like meta keys
* Fixed: Uninstall removes uploaded language pack files under uploads/wpmmcc-ats/languages/

= 1.7.0 =
* Fixed: WordPress.org plugin review feedback (Tested up to 6.9, external services documentation)
* Fixed: Language packs written to WordPress languages directory instead of plugin folder
* Fixed: Removed unnecessary core-file includes in media mapping
* Fixed: Manual-translation REST write endpoint requires manage_options
* Fixed: Option sync/read allowlist; sanitized translation keys and HTTP_HOST input
* Fixed: readme tags and client install instructions; client token page copy

= 1.5.2 =
* Fixed: All features are now unconditionally free (no license checks)
* Fixed: Security hardening (nonce verification, input sanitization)
* Fixed: Text domain alignment with plugin slug
* Added: register_meta field discovery layer
* Added: ACF field rules adapter with native API integration
* Added: Elementor per-widget translation schema
* Added: Public translation API functions

= 1.5.0 =
* Initial public release with full translation management suite
