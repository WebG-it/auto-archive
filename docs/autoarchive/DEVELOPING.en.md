# Auto Archive: developer guide

English summary of [DEVELOPING.md](./DEVELOPING.md) (Italian, more detailed).

> Public repository: never commit tokens, passwords, keys, server hosts or customer data.

## Architecture

```
plugins/plg_system_autoarchive/
  autoarchive.xml                manifest (namespace WebG\Plugin\System\Autoarchive)
  script.php                     PHP >= 8.1, Joomla >= 5.0; first install: enables the plugin and
                                 imports the settings of plg_system_yooarchive 1.x
  services/provider.php          Joomla 5/6 service provider
  src/Extension/Autoarchive.php  plugin (SubscriberInterface), warnings for administrators
  src/Automation/Automation.php  archive and move logic
  language/{en-GB,it-IT}/
```

System plugin (not content): the table events and the API state changes reach it even where the
`content` group is not loaded. No dependency on YOOtheme. It is the automation of
`plg_system_yooarchive` 1.2.1 split out, same behaviour and same parameter names.

Migration from yooarchive 1.x (`script.php`, first install only): the seven automation keys are
copied from the parameters of `plg_system_yooarchive`; when the automation was on and that plugin
is still 1.x, `automation` is switched off there so the two never run together; an already
configured Auto Archive is not overwritten. `plg_system_yooarchive` 2.0.0 has the other half
(`preflight`): the update is refused when the automation is on and Auto Archive is missing or
disabled. Redirects stored by 1.2.x (note `Archive Layouts for YOOtheme:`) stay under the control
of Auto Archive (`Automation::LEGACY_REDIRECT_MARKER`).

Automation: `onTableBeforeStore` remembers category and state; `onTableAfterStore` queues an
archive or a move; `onContentChangeState` handles list/toolbar/API state changes; the queue runs
on `onContentAfterSave` / `onContentCleanCache`. Archive = `ArticleModel::publish($pks, 2)`;
move = `Table::store()` with the new `catid` plus `onFinderAfterSave`. Moves need a logged-in user
with `core.edit.state` on the article (and `core.create` on the target category when *Require
create permission* is on), skip articles checked out by others, never run for guests or CLI.
Screen messages are generic language strings; details go to `Log::add()` only. A static `$busy`
flag prevents recursion. An invalid (missing or trashed) archive category pauses the automation.

Redirects (option *301 redirect from the old address*, since 1.2.0): the public URL is built with
`Route::link('site', RouteHelper::getArticleRoute(id, catid, language))` before and after the
category change (in `move()` for the plugin's own moves, in `onTableBeforeStore` for a published
article a user brings into the category, with the stored category and alias). When the URLs
differ and are SEF, a row is stored in `#__redirect_links` through the com_redirect `Link` table
(301; an existing row with the same old URL is updated; a row starting from the new URL, now a
live page again, is unpublished). The core *System - Redirect* plugin serves it on 404 pages;
administrators are warned when it is off. 4SEO does not create redirects by itself when an
article URL changes (checked with 4SEO 7.2.0). Since 1.2.1 the plugin only updates or unpublishes
rows whose note starts with `Automation::REDIRECT_MARKER` (`Auto Archive:`) or `LEGACY_REDIRECT_MARKER`; an existing row for the same old URL
without the marker is left alone (log line only). The log is rotated above 1 MB to
`plg_system_autoarchive.1.php` (kept as .php for Joomla's `die()` guard).

## Test environment and release

Same Docker environment as Archive Layouts (`dev/yooarchive/`, see
[its guide](../yooarchive/DEVELOPING.en.md#test-environment-docker)). Auto Archive does not need
YOOtheme but the environment is used to test the migration from 1.x. Dev scripts:
`automation_on.php <alias> [yooarchive|autoarchive]`, `api_state.php`, `mk_published.php`,
`batch_ui.php` (Batch as the browser does it), `security_test.php`, `set_debug.php`.

```bash
python build/build_plugin.py plugins/plg_system_autoarchive
```

Release like the other plugins (version in `autoarchive.xml`, changelogs, JED Checker `master` and
`develop`, `dist/`, `updates/autoarchive.xml`, `collection.xml`, tag `autoarchive-X.Y.Z`). Repeat
the migration paths at every release (automation on or off, right and wrong order).
