# Release Notes for SEOmatic AI

## 1.0.0 - 2026-11-11

### Added
- Key-authenticated JSON API for SEOmatic (`/actions/seomatic-ai/api/...`), API version 1: `info`, `sections`, `entries`, `entry`, `save-entry`, `create-entry`, `delete-entry`, `asset-alt`, `redirects`, `redirect-save`, `redirect-delete`, `robots`, `robots-save`, `schema`, `schema-save`.
- Works on Craft 4 and Craft 5, every edition including Solo; no GraphQL needed.
- Meta written into nystudio107 SEOmatic's "SEO Settings" field when an entry has one; otherwise rendered into the page head by the plugin.
- JSON-LD per entry and site-wide, rendered before `</head>`.
- Redirects (301, 302, 307, 410) answered before Craft routes the request, with hit counts.
- robots.txt lines: served by the plugin, or appended to nystudio107 SEOmatic's robots.txt.
- Connection key screen with copy and regenerate.
- Redirect sources are matched on the full path from the host root, on sites with a base path such as `/fr/` too; `/path` destinations go to the site's own host.
- Writes that change a page without saving the entry (meta, schema, redirects) clear that page from Craft's caches and from Blitz.
- `robots` reports `staticFile`, `url` and `hostRoot`.
- `entry` reads a Matrix body (read-only) and reports `bodySource`; fields report `translatable`.
- `create-entry` on a multi-site section keeps the other sites' copies disabled (`propagatedTo`); `delete-entry` takes `scope: "site"`.
