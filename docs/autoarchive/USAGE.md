# Auto Archive: user guide

For **site managers**: install, set the archive category, update, uninstall. Everything from the
Joomla interface. Italian version: [USO.md](./USO.md). Developers: [DEVELOPING.md](./DEVELOPING.md)
(Italian) and [DEVELOPING.en.md](./DEVELOPING.en.md).

## Why it exists

For the people who run a site the Joomla **Archived** state is invisible, while a category
called "Sold" or "Past events" can be seen, opened and filled. That is why almost everyone
archives by moving the article into a folder, almost nobody uses the state, and the two gestures
stay apart: dragging an article into "Sold" leaves it published, clicking **Archive** leaves it in
its old category, and either way the article is only half done. Auto Archive makes the two
gestures one and the same: moving into "Sold" means archiving, clicking **Archive** means ending
up in "Sold", and whatever the editor does, the site stays consistent.

The folder matters more than the state for three reasons. Everything on the site that works by
category rather than by state (modules, filters, counters, searches, feeds, sitemaps) stops
seeing an article only when its category changes. One archive category fed by many
subcategories is simpler to manage than ten separate filters. And the only side effect of the
move, the change of address, is repaired by the plugin itself with a **301 redirect**.

To design the archived articles page with the YOOtheme Pro builder you do not need this plugin
but [Archive Layouts for YOOtheme](https://github.com/WebG-it/archive-layouts/blob/main/docs/yooarchive/USAGE.md): even two distinct archives with two
different layouts are just two *Archived Articles* menu items filtered by category, with nothing
moved. Auto Archive is for those who want their archived articles in a folder of their own.

## What it does

Joomla has an **Archived** state for articles, but an archived article stays in the category where
it was. Auto Archive keeps the archived articles of your site **in one category** you choose:

- you click **Archive** (list, toolbar, edit form or Web Services API) and the article is
  **moved to the archive category**;
- you save a published article into that category, or move it there with **Batch**, and it is
  **archived** (through the normal Joomla article model, so Smart Search, cache, versions and
  action logs are updated as with a manual click);
- optional **301 redirect** from the old address of the article to the new one, when the category
  is part of the article URL.

It is plain Joomla: it works with or without YOOtheme. It is off by default. (To design the
Archived Articles page with the YOOtheme Pro builder see
[Archive Layouts for YOOtheme](https://github.com/WebG-it/archive-layouts/blob/main/docs/yooarchive/USAGE.md), a separate plugin.)

Requirements: Joomla 5 or 6, PHP 8.1+.

## 1. Install

1. Download `plg_system_autoarchive-X.Y.Z.zip` from the
   [releases](https://github.com/WebG-it/auto-archive/releases) or from `dist/`.
2. *System > Install > Extensions > Upload Package File*.
3. The plugin is enabled automatically. Nothing happens until you switch on the automation and
   choose the category.

Coming from Archive Layouts for YOOtheme 1.x? See [Moving from Archive Layouts 1.x](#moving-from-archive-layouts-1x).

## 2. Options

*System > Plugins > System - Auto Archive*:

| Option | What it does |
|---|---|
| Enable automation | Switches everything below on. |
| Archive category | Where archived articles go. Without a category, or if the category is deleted or trashed, the automation pauses and administrators see a warning. It must be **published**: otherwise moved articles do not appear on the public site and administrators see a yellow warning. |
| Require create permission | On by default: move only if the user who archives can also create articles in the archive category. |
| Move archived articles | Archive (list, toolbar, edit form or API) = move to the category. |
| 301 redirect from the old address | On by default. When the category is part of the article URL, entering the archive category changes the address: the old one is redirected (301) to the new one through the Joomla *Redirects* component, for the moves made by the plugin and for the articles a user moves into the category. Needs the core *System - Redirect* plugin on (administrators see a warning if it is off). Nothing is stored when the address does not change. |
| Archive articles moved into the category | A published article saved or moved (also with *Batch*) into the category is archived. |
| Force archived | Off by default: an article already in the category can be republished on purpose. On: it is archived again every time. |

After each action a blue message tells what the plugin did (also how many redirects it stored:
you find them in *System > Redirects*).

Languages: moves ignore the language. An English article is moved to the archive category even
if that category is Italian; on a multilingual site use an archive category with language *All*.

Redirects are written by the plugin, so the user does not need permissions on the Redirects
component; they are written only for the moves allowed by the rules below. Existing redirects for
the same old address that the plugin did not create are never changed.

Permissions: an article is moved only when a logged-in user with *Edit State* on the article
archives it (and, with the option on, with *Create* in the archive category). Articles being
edited by another user are never touched. Guests and the command line never move anything.

If something goes wrong (missing permission, duplicate alias in the target category) a generic
yellow message appears; details (article ids, database error) go only to the log
`administrator/logs/plg_system_autoarchive.php` (rotated above 1 MB, one previous file kept). In
the frontend (article editing) messages are shown only to users who can manage articles.

Limits: one archive category per site; not tested with Joomla *Workflows* enabled (the default is
off).

## Moving from Archive Layouts 1.x

Up to version 1.2 the automation was inside *Archive Layouts for YOOtheme*. In version 2.0 of that
plugin it is no longer there: it is this plugin.

1. Install Auto Archive. If Archive Layouts 1.x had the automation configured, Auto Archive
   **imports the settings** (category and options) by itself, and **switches the old automation
   off** so the two never run together. A message tells you what was imported. Check the options
   once.
2. Then update Archive Layouts for YOOtheme to 2.0. If you do it **before** installing Auto Archive
   and the automation was on, the update is **refused with a message and nothing is changed**: the
   old version keeps working.
3. The redirects that 1.x stored stay in *System > Redirects* and remain managed by Auto Archive.

## Notes for 4SEO users

4SEO does not create a redirect when an article changes URL: keep the option *301 redirect from the
old address* on (with the core *System - Redirect* plugin on) if the category is part of your
article URLs. After archiving many articles at once run a new site analysis in 4SEO (*Pages >
Reset analysis*) so that sitemap and canonical tags use the new addresses. With article URLs that
contain the id (`/<id>-alias`) Joomla finds the article by id whatever the category in the address,
so the old URL stays live after the move (no 404) and the redirect is not used.

## 3. Update

Updates arrive in *System > Update > Extensions* like any other extension (WebG update server).

## 4. Uninstall

*System > Manage > Extensions*, search "Auto Archive", *Uninstall*. No tables or plugin files are
left. The redirects stored by the plugin stay in *System > Redirects* (their note starts with
"Auto Archive:"; delete them there if you want) and so does the log in `administrator/logs/`.

## Support

Questions and bug reports: [GitHub issues](https://github.com/WebG-it/auto-archive/issues)
(please write the Joomla and PHP versions). Never post passwords, keys or private data in an issue.
