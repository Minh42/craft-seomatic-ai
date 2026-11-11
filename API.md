# SEOmatic AI for Craft CMS: plugin API contract (v1)

The Craft plugin (`integrations/craft/seomatic-ai`, Composer package
`seomatic/craft-seomatic-ai`, plugin handle `seomatic-ai`) exposes a small
JSON API that the SEOmatic app (lib/cms/craft-plugin-client.ts) calls. This
file is the contract both sides hold to. Change it first, then both sides.

Works on Craft 4.0+ and 5.x, every edition (Solo included): it uses
controller action URLs, not GraphQL, so no route config, no GraphQL schema
and no Craft Pro are needed.

## Transport

- Base: `{siteOrigin}/actions/seomatic-ai/api/{action}`. The app falls back
  to `{siteOrigin}/index.php?action=seomatic-ai/api/{action}` when the
  first answers 404 (sites without path rewriting).
- Auth: header `X-Seomatic-Key: {key}`. Not `Authorization` (Apache strips
  it by default). Compared with `hash_equals`. Missing or wrong key ->
  HTTP 401 `{ok:false,error:{code:'unauthorized'}}`.
- The key: `smk_` followed by 44 random URL-safe characters
  (`A-Z a-z 0-9 - _`), 48 characters in total, generated on install and on
  every regenerate. The app tells a plugin key apart from a Craft GraphQL
  token by the `smk_` prefix. Shown (with
  copy and regenerate) on the plugin's settings screen in the control panel.
  Stored in the plugin's own table, never in project config (project config
  is committed to git).
- GET actions take query parameters; POST actions take a JSON body
  (`Content-Type: application/json`). CSRF is not required for these
  actions (key-authenticated, anonymous allowed).
- Every answer is JSON: `{ok:true, ...}` or
  `{ok:false, error:{code, message}}` with a 4xx/5xx status.
  Codes: `unauthorized`, `not_found`, `invalid`, `unsupported`,
  `save_failed`, `server_error`. Every plugin answer carries a boolean
  `ok`: the app treats JSON without it (Craft's own 404 or exception page)
  as "the plugin is not installed here".
- `siteId` is optional everywhere; absent = the primary site. Each Craft
  site (a language under a path, or another domain) has its own version of
  an entry: reads and writes touch ONLY the given site's version
  (`getEntryById($id, $siteId)`). The app names a non-primary site's entry
  `"{id}@{siteId}"` in its own inventory and always sends `siteId`.
- `info.sites[].baseUrl` is absolute, with Craft's env vars and aliases
  resolved (never `@web` or `$PRIMARY_SITE_URL`): the app matches page URLs
  to sites with it.

## Actions

### GET `info`
```json
{ "ok": true,
  "plugin": { "version": "1.0.0", "apiVersion": 1 },
  "craft": { "version": "5.5.2", "edition": "solo|team|pro" },
  "php": "8.2.12",
  "sites": [{ "id": 1, "handle": "default", "baseUrl": "https://example.com/", "primary": true, "language": "en-US" }],
  "seomatic": { "installed": true, "version": "5.1.11" },
  "capabilities": { "entries": true, "meta": true, "schema": true, "redirects": true, "robots": true, "altText": true, "featuredImage": true },
  "robots": { "servedBy": "seomatic-ai|seomatic|none", "staticFile": false } }
```
`robots.servedBy`: who answers /robots.txt from Craft (nystudio SEOmatic
when installed and its robots.txt is on; else this plugin). `none` =
neither does: the site has its own robots.txt template or route, which the
plugin never takes over. `seomatic-ai` = the plugin answers /robots.txt,
but only while lines are stored (before the first robots-save the site's
/robots.txt is untouched).

With nystudio SEOmatic's SEO Settings field, a key the field has switched
off (canonical and robots are off by default) is kept and rendered by this
plugin instead; `meta` returns what takes effect either way. `GET
redirects?siteId` lists that site's rules plus the every-site rules.
`redirect-delete` answers `{ok:true, deleted:bool}` (an unknown id is not
an error). A static
`robots.txt` file in the web root wins over both at the web server:
`robots.staticFile: true` when one exists (looked up in the folder of the
index.php serving the request, the server's document root, then @webroot,
so `public/` and other web roots count). The app proves the live file
itself.

### GET `sections?siteId`
```json
{ "ok": true,
  "sections": [{ "handle": "blog", "name": "Blog", "type": "channel|structure|single",
    "entryTypes": [{ "handle": "post", "name": "Post",
      "fields": [{ "handle": "body", "name": "Body", "kind": "text|html|matrix|assets|seomatic|other", "writable": true, "translatable": true }] }] }] }
```
`translatable: false` = one value shared by every site (the field's
translation method is "not translatable"): writing it for one site changes
all of them.
Only sections enabled for that site. `kind` and `writable` follow the same
rules as `entry.fields`. Used for the publish destination picker and field
mapping.

### GET `entries?siteId&section&slug&offset=0&limit=100`
All statuses (live, pending, expired, disabled). Only entries in a section
(nested/Matrix entries are skipped). `limit` max 500. `slug` optional:
exact match, all statuses, combinable with `section` (the app uses it to
find an existing entry before creating one).
```json
{ "ok": true, "total": 312,
  "entries": [{ "id": 12, "siteId": 1, "title": "...", "slug": "...", "uri": "blog/x",
    "url": "https://example.com/blog/x", "status": "live", "enabled": true,
    "sectionHandle": "blog", "typeHandle": "post",
    "dateUpdated": "2026-01-02T03:04:05+00:00", "postDate": "..." }] }
```

### GET `entry?id&siteId`
```json
{ "ok": true,
  "entry": { "...entries fields...": "",
    "bodyField": "body",
    "body": "<p>html</p>",
    "bodySource": "field|matrix|null",
    "fields": [{ "handle": "body", "name": "Body", "kind": "text|html|matrix|assets|seomatic|other", "writable": true, "translatable": true }],
    "meta": { "title": "...", "description": "...", "canonical": null, "noindex": false,
              "source": "seomatic_field|plugin|none", "stored": true },
    "images": [{ "id": 55, "url": "https://...", "alt": "..." , "field": "featuredImage" }] } }
```
- `bodyField`: the first writable text/html field among `body`, `content`,
  `postContent`, `articleBody`, `text`, `richText`, `copy`, `mainContent`
  (in that order), else the first CKEditor/Redactor field on the layout,
  else `null`.
- `bodySource`: `field` when `body` comes from `bodyField`. When there is
  no `bodyField` but the layout has a Matrix field, `body` is the text of
  that Matrix's blocks (nested entries on Craft 5, blocks on Craft 4) in
  order: CKEditor/Redactor HTML as is, plain text wrapped in `<p>`, blocks
  joined by a blank line; `bodySource: matrix`, `bodyField: null`. Read
  only: `body` on save-entry is then dropped ("this entry type has no body
  field") and a Matrix field is `kind: matrix`, `writable: false`. `null`
  with `body: null` when there is neither.
- `meta`: the STORED custom values, never the rendered page title (no
  site-name suffix, no template fallback): from the nystudio SEOmatic
  "SEO Settings" field when the entry has one (`source: seomatic_field`;
  a value counts only when its `metaBundleSettings.*Source` is
  `fromCustom`, else null), else this plugin's own stored override
  (`source: plugin`), else `none` with nulls. `stored: false` when every
  value is null: the page shows its template's own title, which this answer
  does not carry. The app compares what it wrote with these values.

### POST `save-entry`
```json
{ "id": 12, "siteId": 1,
  "title": "...", "slug": "...", "body": "<p>...</p>",
  "fields": { "summary": "plain text" },
  "meta": { "title": "...", "description": "...", "canonical": "https://...", "noindex": false },
  "enabled": true }
```
Every key optional. `body` goes to `bodyField`. `fields` only accepts
writable text/html fields. `meta`: written into the SEOmatic "SEO Settings"
field (`metaGlobalVars.seoTitle` / `seoDescription` / `canonicalUrl` /
`robots`, with the matching `metaBundleSettings.*Source` set to
`fromCustom`) when the entry has one, else into the plugin's own override
table (rendered into the page head by the plugin). Saved with
`Craft::$app->elements->saveElement()`; validation errors -> `save_failed`
with the messages. Answer: the entry as `entry` returns it after the save
(read back), plus `written: ["title","meta.title",...]` and
`dropped: [{ "key": "fields.hero", "reason": "not a text field" }]`.

### POST `create-entry`
```json
{ "section": "blog", "type": "post", "siteId": 1, "title": "...", "slug": "...",
  "body": "<p>...</p>", "fields": { "summary": "plain text" },
  "enabled": false, "postDate": "2026-11-11T10:00:00Z",
  "meta": { "title": "...", "description": "..." },
  "featuredImage": { "url": "https://...", "alt": "...", "field": "featuredImage" } }
```
`type` optional (the section's first entry type). `fields` as in
save-entry (writable text/html fields only, others in `dropped`). The app
waits up to 90 s (the image download). `featuredImage` optional:
downloaded (http/https only, max 15 MB, image types only) into the asset
field's default upload location; skipped with a `dropped` reason when the
type has no asset field. Answer as `save-entry`, plus
`propagatedTo: [{ "siteId": 2, "enabled": false }]`: the other sites the
section copies the new entry to. Those copies are always created disabled
(Craft would otherwise publish the same text on every site); `enabled`
applies only to `siteId`. `[]` on a single-site section.

### POST `delete-entry` `{ "id": 12, "siteId": 2, "scope": "all|site" }`
`scope` optional, default `all`: soft delete (Craft's trash) of the entry
on EVERY site. `{ok:true, outcome:"trashed"}`; an id already gone ->
`{ok:true, outcome:"deleted"}`. `scope: "site"` touches only `siteId`'s
version: `removed_from_site` (Craft's "Delete for site"), or
`disabled_on_site` when the section keeps a version on every site
(propagation "all sites", where Craft re-creates it); `trashed` when that
site is the entry's only one.

### POST `asset-alt` `{ "id": 55, "alt": "..." }`
Craft 4+ native asset `alt`. Read back: `{ok:true, alt:"..."}`.

### GET `redirects`
`{ok:true, redirects:[{ "id": 3, "siteId": null, "source": "/old", "destination": "/new", "statusCode": 301, "hits": 4, "lastHit": "...", "dateCreated": "..." }]}`

### POST `redirect-save` `{ "siteId": 2, "source": "/old-path", "destination": "/new-path or https://...", "statusCode": 301 }`
`siteId` optional: null/absent = served on every site; set = served only
on that site's requests (an install with several domains). Upsert by
(normalized source, siteId); normalized = path only, leading slash, no
trailing slash except "/", lower-cased, query kept. `statusCode` in 301,
302, 307, 410. Sources are the path from the host root, as in the address
bar: on a site at `https://example.com/fr/` a French page is
`/fr/old-page`. A `/path` destination is sent to the site's own host
(from its base URL).
Answer `{ok:true, redirect:{...}}`.

### POST `redirect-delete` `{ "id": 3 }` -> `{ok:true}`

The plugin serves its redirects on site (front-end) requests before Craft
routes them, for GET/HEAD only, never for control-panel, action or console
requests. A matching source answers the status code with `Location` (410:
no Location). Exact-match only. A redirect whose destination is its own
source is refused at save (`invalid`).

### GET `robots`
`{ok:true, servedBy:"seomatic-ai|seomatic|none", staticFile:false, url:"https://example.com/robots.txt", hostRoot:true, lines:["Sitemap: https://..."], body:"...full robots.txt the plugin would serve..."}`

Lines are per site and served at that site's own `/robots.txt`: `url`.
`hostRoot: false` when the site has a base path (`https://example.com/fr/`
-> `https://example.com/fr/robots.txt`), which crawlers never read; save
lines on the site whose base URL is the host root. `staticFile` as in
`info`.

### POST `robots-save` `{ "lines": ["# BEGIN SEOmatic", "Sitemap: https://example.com/sitemap.xml", "# END SEOmatic"] }`
The extra lines the plugin keeps, printed verbatim and in order as the
last lines of robots.txt (no dedupe, no reordering). The app sends its own
`# BEGIN SEOmatic` / `# END SEOmatic` fence lines, which its live check
looks for. `[]` clears. When `servedBy` is `seomatic-ai` the
plugin serves /robots.txt as `User-agent: *\nDisallow:\n` (or the default
Craft rules) plus these lines. When nystudio SEOmatic serves robots.txt,
the lines are appended to its output. Answer `{ok:true, servedBy, staticFile, url, hostRoot, lines}`.

Page caches: after save-entry, schema-save and redirect-save the plugin
clears the affected page from Craft's caches
(`invalidateCachesForElement`) and, when Blitz is installed, refreshes it
in Blitz (the entry URL; the whole site for site-wide schema; the source
URL on each site the redirect applies to). Other caches (CDN, Varnish)
are not touched.

### GET `schema?entryId&siteId`
`{ok:true, entry:[{...json-ld...}], site:[{...}]}`

### POST `schema-save` `{ "entryId": 12 | null, "siteId": 1, "items": [{ "@context": "https://schema.org", "@type": "FAQPage", "...": "" }] }`
Replaces the stored JSON-LD for that entry (or the site-wide set when
`entryId` is null). Every item must be a JSON object with `@type`. The
plugin renders each as `<script type="application/ld+json">` before
`</head>` on the entry's page (site-wide items on every page).
`items: []` clears.

## Rendering (the plugin's own head output)

On front-end HTML page renders only (`View::EVENT_AFTER_RENDER_PAGE_TEMPLATE`),
for the matched entry (`Craft::$app->urlManager->getMatchedElement()`):
- plugin-stored meta overrides replace the page's `<title>`,
  `meta[name=description]`, `link[rel=canonical]` and add/replace
  `meta[name=robots]` (`noindex` only). Only when the entry has no SEOmatic
  field (the field is written natively instead).
- stored JSON-LD items are inserted before `</head>`.
Nothing is changed on a page without stored data. Output that is not HTML
(no `</head>`) is left alone.
