# Auto Archive (plg_system_autoarchive)

Joomla system plugin by WebG. It keeps the **archived articles of a site in one category**:
archiving an article moves it to the category you choose, and a published article saved or moved
(also with Batch) into that category is archived through the Joomla article model, so Smart
Search, cache, versions and action logs stay in sync. Optional 301 redirects from the old address.
Plain Joomla: works with or without YOOtheme.

- Joomla 5.x and 6.x, PHP 8.1 or later.
- Off by default; permission-aware (the user needs *Edit State*, and *Create* in the archive
  category if you keep that option on); never acts for guests or the command line.
- License: GNU GPL version 2 or later (see `LICENSE.txt`).

User guide: https://github.com/WebG-it/joomla-extensions/blob/main/docs/autoarchive/USAGE.md
(Italian: `docs/autoarchive/USO.md`).

## Options

*System > Plugins > System - Auto Archive*: Enable automation, Archive category, Require create
permission, Move archived articles, 301 redirect from the old address, Archive articles moved into
the category, Force archived (off by default).

## Moving from Archive Layouts for YOOtheme 1.x

The automation used to be part of *Archive Layouts for YOOtheme* (up to 1.2.x). On install, Auto
Archive imports those settings and switches the old automation off. Archive Layouts 2.0 refuses to
update while the automation is on and Auto Archive is not installed, enabled and switched on, so nothing is lost.

## Uninstall

*System > Manage > Extensions > Uninstall*. No tables or files outside the plugin folder are kept.
The redirects stored by the plugin stay in *System > Redirects* (note starting with "Auto Archive:")
and the log stays in `administrator/logs/`.

## Development

Source, build script and Docker test environment: https://github.com/WebG-it/joomla-extensions
