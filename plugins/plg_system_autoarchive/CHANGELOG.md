# Changelog

## 1.1.0 (2026-10-17)

Redirects in 4SEO.

- Redirects can now be stored as 4SEO redirect rules. New option "Store the redirects in": Automatic (default) uses 4SEO when it is installed, enabled and its redirects are on, otherwise the Joomla Redirects component as before; or force one of the two.
- No double jumps in 4SEO: a redirect rule that already points to the old address of a moved article (an earlier move, or one made by hand) is pointed to the new address; a rule of this plugin that starts from the new address, now a live page again, is switched off; a rule for the old address that the plugin did not create is never changed.
- The update server now lives in the repository of the plugin (github.com/WebG-it/auto-archive). Nothing else changes for people who do not use 4SEO.
- Works on Joomla 5.0 and 5.1 too (the queries no longer need the createQuery method of Joomla 5.2).

## 1.0.1 (2026-10-17)

Description and guides: why it exists. No change in behaviour.

- The short description shown in *System > Extensions* (en-GB and it-IT) now says what the plugin
  is for: archived articles in a category of their own, archiving moves, moving archives, 301
  redirect when the address changes.
- User guides (USAGE.md, USO.md) and README: new section explaining why the archive lives in a
  category and not only in the Archived state, and that Archive Layouts for YOOtheme alone is
  enough to design an archive page, even two distinct archives filtered by category.

## 1.0.0 (2026-10-17)

First release. The archive automation of *Archive Layouts for YOOtheme* 1.2.1, now a separate
plugin that does not need YOOtheme. Same behaviour.

- Archived article: moved to the archive category (list, toolbar, edit form, API).
- Published article saved or moved (also Batch) into the archive category: archived through the
  article model (Smart Search, cache, versions, action logs).
- *Force archived*, off by default.
- 301 redirect from the old address when the category is part of the article URL; the redirects
  stored by Archive Layouts 1.2.x (note "Archive Layouts for YOOtheme:") stay under its control.
- Permission-aware (Edit State, Create), skips articles checked out by others, never acts for
  guests or the command line; generic screen messages, details only in the log
  `administrator/logs/plg_system_autoarchive.php` (rotated above 1 MB).
- On first install it imports the automation settings of Archive Layouts for YOOtheme 1.x and
  switches that old automation off.
- en-GB and it-IT languages.
