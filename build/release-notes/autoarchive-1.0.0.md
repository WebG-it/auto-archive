## Auto Archive 1.0.0

Keeps the archived articles of a Joomla site **in one category**, with or without YOOtheme.

- **Archive** an article (list, toolbar, edit form or API) and it is **moved** to the archive category you choose.
- Save a published article into that category, or move it there with **Batch**, and it is **archived** through the Joomla article model (Smart Search, cache, versions and action logs stay in sync).
- Optional **301 redirect** from the old address when the category is part of the article URL.
- *Force archived* is off by default. Off by default as a whole; permission-aware (Edit State, and Create in the archive category by default); never acts for guests or the command line; log in `administrator/logs/plg_system_autoarchive.php`.
- English and Italian.

It is the automation of *Archive Layouts for YOOtheme* 1.2.1, now a separate plugin with the same
behaviour. **Coming from Archive Layouts 1.x**: installing Auto Archive imports the automation
settings and switches the old automation off. Archive Layouts 2.0.0 refuses to update while the
automation is on and Auto Archive is not installed, enabled and switched on.

Requirements: Joomla 5 or 6, PHP 8.1+. Install: *System > Install > Extensions > Upload Package File*
with `plg_system_autoarchive-1.0.0.zip`; updates arrive from the WebG update server.

**Checked** on Joomla 6.2.0 and 5.4.10: archive, move by API, Batch, force archived on and off,
redirects (including a manual redirect left untouched and a redirect from 1.2.x taken over),
install with and without Archive Layouts, with and without YOOtheme Pro, and with Essentials for
YOOtheme Pro 3.0.15 and 4SEO 7.2.0 active. JED Checker `master` and `develop`: no errors, no
warnings, one notice about the `<licence>` element.

Docs: [user guide](https://github.com/WebG-it/joomla-extensions/blob/main/docs/autoarchive/USAGE.md) ·
[guida in italiano](https://github.com/WebG-it/joomla-extensions/blob/main/docs/autoarchive/USO.md) ·
[changelog](https://github.com/WebG-it/joomla-extensions/blob/main/plugins/plg_system_autoarchive/CHANGELOG.md)
