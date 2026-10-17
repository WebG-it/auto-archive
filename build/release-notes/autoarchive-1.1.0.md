## Auto Archive 1.1.0

The 301 redirect of a moved article can now live in **4SEO**.

**New**
- Option *Store the redirects in*: *Automatic* (default) uses a 4SEO redirect rule when 4SEO is installed, enabled and its redirects are on, and the Joomla *Redirects* component otherwise (as in 1.0); or force *Joomla Redirects* / *4SEO redirect rules*.
- No double jumps: a 4SEO rule that already points to the old address is pointed to the new one; a rule of this plugin that starts from the new address (a live page again) is switched off; a rule for the old address that the plugin did not create is never changed.

**Changed**
- The update server is in this repository. Works on Joomla 5.0 and 5.1 too.

**Checked**: Joomla 6.2.0 and 5.4.10 with 4SEO 7.2.0 (rule created and served as 301, one jump after two moves, rules made by hand untouched, fallback to Joomla Redirects when 4SEO is off or its redirects are off), update from 1.0.1 in place with settings kept, JED Checker `master` and `develop`: no errors, no warnings.

Update from *System > Update > Extensions*, or install `plg_system_autoarchive-1.1.0.zip` with *System > Install > Extensions > Upload Package File*.

Docs: [user guide](https://github.com/WebG-it/auto-archive/blob/main/docs/autoarchive/USAGE.md) · [guida in italiano](https://github.com/WebG-it/auto-archive/blob/main/docs/autoarchive/USO.md)
