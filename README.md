# Auto Archive

Joomla system plugin by [WebG](https://www.webg.it), free software under the GNU GPL v2 or later.

For the people who run a site the Joomla **Archived** state is invisible, while a category called
"Sold" or "Past events" can be seen and filled, so editors move articles into a folder or click
*Archive*, and the two gestures do not match: one leaves the article published, the other leaves
it in its old category. Auto Archive makes them one and the same, keeps all archived articles
**in one category of their own** (which is what modules, filters, counters, feeds and sitemaps
actually look at) and repairs the only side effect of the move, the change of address, with a
301 redirect. Plain Joomla, with or without YOOtheme. It is not needed to design the archive
page: [Archive Layouts for YOOtheme](https://github.com/WebG-it/archive-layouts) does that, even
for several archives filtered by category.

- **Archive** an article (list, toolbar, edit form or API) and it is moved to the category you choose.
- Save a published article into that category, or move it there with **Batch**, and it is archived
  through the Joomla article model (Smart Search, cache, versions and action logs stay in sync).
- Optional **301 redirect** from the old address when the category is part of the article URL, stored as a
  **4SEO redirect rule** when 4SEO is active, otherwise in Joomla Redirects (your choice in the options). A rule that
  already pointed to the old address is pointed to the new one: no double jumps.
- Off by default, permission-aware, never acts for guests or the command line. English and Italian.

**Requirements**: Joomla 5 or 6, PHP 8.1+. Tested on Joomla 6.2.0 and 5.4.10, PHP 8.4, also with
YOOtheme Pro 5.0.51, Essentials for YOOtheme Pro 3.0.15 and 4SEO 7.2.0 active.

**Install**: download `plg_system_autoarchive-1.1.0.zip` from the
[releases](https://github.com/WebG-it/auto-archive/releases) (or [dist/](./dist)), then
*System > Install > Extensions > Upload Package File*. Full guide: [USAGE.md](./docs/autoarchive/USAGE.md)
(italiano: [USO.md](./docs/autoarchive/USO.md)). Coming from Archive Layouts for YOOtheme 1.x:
Auto Archive imports the automation settings.

**Updates**: automatic, in *System > Update > Extensions*. The update server of this plugin is
[`collection.xml`](./collection.xml) in this repository
(`https://raw.githubusercontent.com/WebG-it/auto-archive/main/collection.xml`), which points to [`updates/autoarchive.xml`](./updates/autoarchive.xml).
From 1.1.0 the plugin checks for updates here (the 1.0.x releases declared the old shared collection of
[joomla-extensions](https://github.com/WebG-it/joomla-extensions); no known site has them).

## Repository

```
plugins/plg_system_autoarchive/  plugin source
dist/                            installable zips (one per released version)
collection.xml                   update server of this plugin (one row)
updates/autoarchive.xml          its details: version, download, sha256
changelogs/autoarchive.xml       changelog feed shown by Joomla
build/release-notes/             text of each GitHub release
docs/autoarchive/                user guide (USAGE, USO) and developer guide (DEVELOPING)
```

Build script, Docker test sites (Joomla 5 and 6) and JED Checker helpers are shared by all WebG
plugins and live in [joomla-dev-tools](https://github.com/WebG-it/joomla-dev-tools): not copied here.
A new version always changes the version number: two different zips never share one.

Support: [GitHub issues](https://github.com/WebG-it/auto-archive/issues) (write your Joomla and
PHP versions; never post passwords, keys or private data).

License: GNU GPL v2 or later.
