# JED submission: ready-to-paste fields (plg_system_autoarchive)

Description must be in English.

| Field | Value |
|---|---|
| Extension Name | `Auto Archive` |
| Version | `1.1.0` |
| Extensions file | `dist/plg_system_autoarchive-1.1.0.zip` |
| Includes external libraries | No |
| Category | `Core Enhancements` > `Content Enhancements` or `Administration` > `Content Management`; pick the closest the form lists |
| Tags | archive, articles, redirect, category, 4seo |
| Project homepage | https://github.com/WebG-it/auto-archive |
| Download | https://github.com/WebG-it/auto-archive/releases |
| Direct download URL | https://raw.githubusercontent.com/WebG-it/auto-archive/main/dist/plg_system_autoarchive-1.1.0.zip |
| Support | https://github.com/WebG-it/auto-archive/issues |
| Documentation | https://github.com/WebG-it/auto-archive/blob/main/docs/autoarchive/USAGE.md |
| Demo | empty |
| License | GPLv2 or later |
| Compatibility | Joomla 5.0 and 6.0 |
| uses_updater | yes (collection update server) |
| Extension includes | Plugin |
| Logo (16:7) | `assets/autoarchive-jed-logo.png` (1200x525) |
| Screenshots | none yet (plugin options screen would help; to be taken on a test site) |

## Description

**Auto Archive** keeps the archived articles of a Joomla site in one category of their own. For the people who run a site, the Joomla *Archived* state is invisible, while a category called "Sold" or "Past events" can be seen and filled. Editors therefore move articles into a folder or click *Archive*, and the two gestures do not match: one leaves the article published, the other leaves it in its old category. Auto Archive makes them one and the same.

### Features

- **Archive** an article (list, toolbar, edit form or Web Services API) and it is moved to the category you choose.
- Save a published article into that category, or move it there with **Batch**, and it is archived through the Joomla article model, so Smart Search, cache, versions and action logs stay in sync.
- Optional **301 redirect** from the old address when the category is part of the article URL. It is stored as a 4SEO redirect rule when 4SEO is active, otherwise in the Joomla Redirects component (your choice in the options). A rule that already pointed to the old address is pointed to the new one, so there are no double jumps.
- Off by default, permission-aware, and never acts for guests or the command line.
- English and Italian. One-click updates through the Joomla Update System.

### Requirements

Joomla 5 or 6, PHP 8.1 or later. Works with plain Joomla, with or without YOOtheme; 4SEO is optional. Tested on Joomla 6.2.0 and 5.4.10, PHP 8.4.

### Privacy

No external services, no data collected, no tracking.

### Documentation and support

- User guide: https://github.com/WebG-it/auto-archive/blob/main/docs/autoarchive/USAGE.md
- Developer guide: https://github.com/WebG-it/auto-archive/blob/main/docs/autoarchive/DEVELOPING.en.md
- Source code and issue tracker: https://github.com/WebG-it/auto-archive

Released under the GNU General Public License v2 or later.
