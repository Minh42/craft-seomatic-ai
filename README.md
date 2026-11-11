# SEOmatic AI for Craft CMS

Connect your Craft site to [SEOmatic](https://seomatic.ai), the SEO agent. Once connected, SEOmatic can read your entries and write SEO titles, descriptions, canonicals, noindex, JSON-LD schema, redirects, robots.txt lines, image alt text and new entries straight into Craft.

- **Every edition, no GraphQL.** Works on Craft 4 and Craft 5, Solo included. No GraphQL schema, token or Craft Pro is needed.
- **Works with nystudio107 SEOmatic.** When an entry has an SEOmatic "SEO Settings" field, titles and descriptions are written into that field, so SEOmatic keeps rendering them as usual. Other entries get them from this plugin.
- **Your data stays in Craft.** Everything is written into your own entries and the plugin's own database tables. Nothing is kept in project config, and nothing changes on a page until SEOmatic stores something for it.

## Requirements

- Craft CMS 4.0 or later (4.x or 5.x), any edition
- PHP 8.0.2 or later

## Installation

**From the Plugin Store:** in your control panel, go to **Plugin Store**, search for "SEOmatic AI", and press **Install**.

**With Composer:**

```sh
composer require seomatic/craft-seomatic-ai
php craft plugin/install seomatic-ai
```

Then open **SEOmatic AI** in the control panel (or **Settings > Plugins > SEOmatic AI**), copy the key, and paste it into SEOmatic under **Connections > Craft** at [app.seomatic.ai](https://app.seomatic.ai).

On a server where you deploy with Composer, commit `composer.json` and `composer.lock`, deploy, then run `php craft plugin/install seomatic-ai` there (or `php craft up` if the plugin is already in your project config). Plugin installs are recorded in project config like any other plugin; the key is not, so each environment has its own key.

If `composer require` stops with a message about packages with security advisories ("blocked by security advisories"), the problem is a package your Craft version already uses, not this plugin (Composer 2.9 and later block it; some Craft 4 releases pin an affected `enshrined/svg-sanitize`). Update Craft first (`composer update craftcms/cms -W`), then run the `composer require` again.

**From the zip SEOmatic gives you:** unzip `seomatic-ai-craft.zip` into your project's `plugins/seomatic-ai` folder (create `plugins/` next to `composer.json`), add a Composer path repository, then require and install it:

```json
"repositories": [
    { "type": "path", "url": "plugins/seomatic-ai" }
]
```

```sh
composer require seomatic/craft-seomatic-ai
php craft plugin/install seomatic-ai
```

## The connection key

The key is created when the plugin is installed and shown on the plugin's screen, with **Copy** and **Regenerate**. Regenerating creates a new key at once and the old one stops working, so paste the new key into SEOmatic afterwards. Only admins can see the screen; it also works when `allowAdminChanges` is off.

Anyone with the key can change entries and SEO settings through the plugin, so keep it private.

## What SEOmatic can do through the plugin

| | |
|---|---|
| Entries | List entries in every section (all statuses), read one with its body field, fields, meta and images, update title, slug, body, text fields and status, create entries (with a featured image downloaded from a URL), move entries to the trash. |
| Meta | SEO title, description, canonical URL and noindex. Written into nystudio107 SEOmatic's "SEO Settings" field when the entry has one; otherwise the plugin replaces the page's `<title>`, meta description, canonical link and robots meta when the page is rendered. |
| Schema | JSON-LD per entry and site-wide, added before `</head>`. |
| Redirects | 301, 302, 307 and 410 rules, exact match, answered before Craft routes the request, with hit counts. |
| robots.txt | Extra lines (for example `Sitemap:`). Added to nystudio107 SEOmatic's robots.txt when it serves one; otherwise the plugin serves `/robots.txt` itself, but only once lines are saved. A `robots.txt` file in your web folder is served by the web server first and wins. |
| Alt text | Craft's native asset alt text. |

Entries whose body is a Matrix field are read (the text of its blocks) but never written: SEOmatic only writes plain text and rich text fields.

**Page caches.** Meta, schema and redirects are applied when the page is rendered, so after a change the plugin clears that page from Craft's caches. With [Blitz](https://putyourlightson.com/plugins/blitz) installed (including static file caching), it also asks Blitz to refresh the page (the whole site for site-wide schema, the old URL for a new redirect). Other full-page caches (a CDN, Varnish) keep the old page until they expire.

There is no switch for robots.txt: the plugin answers `/robots.txt` only while SEOmatic has saved lines for it, and only when neither nystudio107 SEOmatic nor a `robots.txt` template in your `templates/` folder answers it. Clearing the lines in SEOmatic stops it. The lines are printed exactly as SEOmatic sends them, after everything else.

The plugin answers at `/actions/seomatic-ai/api/...` (or `/index.php?action=seomatic-ai/api/...` without URL rewriting). Requests must carry the key in an `X-Seomatic-Key` header.

## Safety

- Every front-end hook is wrapped: if anything in the plugin fails, the error is logged (`storage/logs`) and your page is served as it would be without the plugin.
- Featured images are downloaded only over http or https, never from private or local network addresses, up to 15 MB, and only JPEG, PNG, GIF, WebP or AVIF (checked from the file itself).
- Meta values containing Twig syntax are refused, so nothing SEOmatic sends is ever run as a template.

## Uninstalling

Uninstall in **Settings > Plugins** or with `php craft plugin/uninstall seomatic-ai`. This removes the plugin's tables: its key, redirects, robots.txt lines, schema and the meta overrides it rendered. Content written into your entries and into SEOmatic "SEO Settings" fields stays.

## Support

[Open an issue](https://github.com/Minh42/craft-seomatic-ai/issues) &middot; [seomatic.ai](https://seomatic.ai) &middot; [app.seomatic.ai](https://app.seomatic.ai)
