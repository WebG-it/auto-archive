# Changelog

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
