<?php

/**
 * @package     WebG.Plugin
 * @subpackage  System.autoarchive
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\System\Autoarchive\Automation;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Event\Finder\AfterSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Content as ContentTable;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Keeps archived articles and one chosen category in sync.
 *
 * - An article that becomes archived (state 2) is moved to the target category.
 * - A published article (state 1) that arrives in the target category, by save, batch
 *   move or API, is archived through the article model, so every other plugin
 *   (Smart Search, cache, versions) sees a normal state change.
 * - With "force archived" on, a published article stored in the target category is
 *   always archived again, even if it was already there.
 * - Moves need a logged-in user with Edit State on the article (and, by default,
 *   Create on the archive category); articles checked out by another user are skipped.
 * - A move changes the article URL when the category is part of it: by default a 301
 *   redirect from the old to the new URL is stored in the Joomla Redirect component,
 *   both for the moves made by the plugin and for the published articles that a user
 *   moves into the archive category (archived right after).
 *
 * Archiving is queued during the table store and executed once the article has been
 * saved (onContentAfterSave, also dispatched by Joomla 6 for each batch-moved article,
 * or onContentCleanCache), never inside the store itself. A static guard prevents any
 * recursion.
 *
 * @since  1.0.0
 */
final class Automation
{
    /**
     * Article states (see ContentComponent::CONDITION_*).
     */
    private const STATE_PUBLISHED = 1;
    private const STATE_ARCHIVED  = 2;

    /**
     * Marker at the start of the comment of every redirect stored by the plugin: only
     * redirects with this marker are ever changed by the plugin.
     */
    public const REDIRECT_MARKER = 'Auto Archive:';

    /**
     * Marker written by Archive Layouts for YOOtheme 1.2.x (the automation used to live
     * there): redirects with this marker were created by this same automation and stay
     * under its control after the migration.
     */
    public const LEGACY_REDIRECT_MARKER = 'Archive Layouts for YOOtheme:';

    /**
     * Size above which the plugin log is rotated (one previous file is kept).
     */
    private const LOG_MAX_BYTES = 1048576;

    /**
     * Name of the plugin log file in the Joomla log folder.
     */
    private const LOG_FILE = 'plg_system_autoarchive.php';

    /**
     * True while the plugin itself is changing articles: nested events are ignored.
     *
     * @var    bool
     * @since  1.0.0
     */
    private static bool $busy = false;

    /**
     * Category and state of the articles before the current store, keyed by id.
     *
     * @var    array<int, array{catid: int, state: int}>
     * @since  1.0.0
     */
    private array $before = [];

    /**
     * Article ids to archive at the next flush.
     *
     * @var    array<int, int>
     * @since  1.0.0
     */
    private array $toArchive = [];

    /**
     * Article ids to move to the target category at the next flush.
     *
     * @var    array<int, int>
     * @since  1.0.0
     */
    private array $toMove = [];

    /**
     * Public URL that an article had before it was moved into the archive category by a
     * user (save or batch), keyed by id: used for the redirect once it is archived.
     *
     * @var    array<int, string>
     * @since  1.0.0
     */
    private array $oldUrls = [];

    /**
     * Where the redirects of this request are stored ('joomla' or 'forseo'), decided on first use.
     *
     * @var    string|null
     * @since  1.1.0
     */
    private ?string $backend = null;

    /**
     * Redirects stored in this request, by store, for the message to the administrator.
     *
     * @var    array<string, int>
     * @since  1.1.0
     */
    private array $redirectCounts = ['joomla' => 0, 'forseo' => 0];

    /**
     * @param   Registry                 $params  The plugin parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @since   1.0.0
     */
    public function __construct(private Registry $params, private CMSApplicationInterface $app)
    {
        self::rotateLog();
        Log::addLogger(['text_file' => self::LOG_FILE], Log::ALL, ['autoarchive']);
    }

    /**
     * Keeps the plugin log small: above 1 MB the file is renamed to
     * plg_system_autoarchive.1.php (replacing the previous one) and Joomla starts a new file.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private static function rotateLog(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        try {
            $dir  = (string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs');
            $file = rtrim($dir, '/\\') .'/' . self::LOG_FILE;

            clearstatcache(true, $file);

            if (is_file($file) && filesize($file) > self::LOG_MAX_BYTES) {
                // Keep the .php extension: the file starts with Joomla's die() guard, so the
                // previous log is never served as plain text.
                @rename($file, substr($file, 0, -4) . '.1.php');
            }
        } catch (\Throwable $e) {
            // Logging must never break the automation.
        }
    }

    /**
     * Remembers the category and state an existing article had before it is stored.
     *
     * @param   mixed  $table  The table being stored.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function beforeStore($table): void
    {
        if (self::$busy || !$table instanceof ContentTable || empty($table->id)) {
            return;
        }

        $id    = (int) $table->id;
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = self::query($db)
            ->select([$db->quoteName('catid'), $db->quoteName('state'), $db->quoteName('language')])
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return;
        }

        $this->before[$id] = ['catid' => (int) $row->catid, 'state' => (int) $row->state];
        $target            = $this->getTarget();

        // Published article being moved into the archive category: remember its current
        // address (built now, with the stored category and alias) for the redirect.
        if (
            (int) $table->catid === $target && (int) $row->catid !== $target
            && (int) $table->state === self::STATE_PUBLISHED
            && $this->params->get('archive_on_move', 1) && $this->params->get('redirect_on_move', 1)
        ) {
            $this->oldUrls[$id] = $this->siteUrl($id, (int) $row->catid, (string) $row->language);
        }
    }

    /**
     * Decides, after an article has been stored, whether it must be archived or moved.
     *
     * @param   mixed  $table   The table that has been stored.
     * @param   bool   $result  The result of the store.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function afterStore($table, bool $result): void
    {
        if (self::$busy || !$result || !$table instanceof ContentTable || empty($table->id)) {
            return;
        }

        $id     = (int) $table->id;
        $catid  = (int) $table->catid;
        $state  = (int) $table->state;
        $target = $this->getTarget();
        $before = $this->before[$id] ?? null;

        unset($this->before[$id]);

        // Published article stored in the target category.
        if ($state === self::STATE_PUBLISHED && $catid === $target && $this->params->get('archive_on_move', 1)) {
            $arrived = $before === null || $before['catid'] !== $target;

            if ($arrived || $this->params->get('force_archived', 0)) {
                $this->toArchive[$id] = $id;
            }

            if (!$arrived) {
                unset($this->oldUrls[$id]);
            }

            return;
        }

        unset($this->oldUrls[$id]);

        // Article archived from the edit form (state changed to archived by a save).
        if (
            $state === self::STATE_ARCHIVED && $catid !== $target && $this->params->get('move_on_archive', 1)
            && ($before === null || $before['state'] !== self::STATE_ARCHIVED)
        ) {
            $this->toMove[$id] = $id;
        }
    }

    /**
     * Reacts to state changes made from the article list, the toolbar or the API.
     *
     * @param   string      $context  The event context.
     * @param   array<int>  $pks      The article ids.
     * @param   int         $value    The new state.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function changeState(string $context, array $pks, int $value): void
    {
        if (self::$busy || !\in_array($context, ['com_content.article', 'com_content.form'], true)) {
            return;
        }

        $pks = array_values(array_filter(array_map('intval', $pks)));

        if (!$pks) {
            return;
        }

        if ($value === self::STATE_ARCHIVED && $this->params->get('move_on_archive', 1)) {
            $this->move($pks);

            return;
        }

        // Republished inside the target category: archive again only when forced.
        if (
            $value === self::STATE_PUBLISHED && $this->params->get('archive_on_move', 1)
            && $this->params->get('force_archived', 0)
        ) {
            $inTarget = $this->filterByCategory($pks, true);

            if ($inTarget) {
                $this->archive($inTarget);
            }
        }
    }

    /**
     * Runs the queued operations. Safe to call many times: it does nothing when the
     * queues are empty or the plugin is already working.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function flush(): void
    {
        if (self::$busy || (!$this->toArchive && !$this->toMove)) {
            return;
        }

        $archive         = array_values($this->toArchive);
        $move            = array_values($this->toMove);
        $this->toArchive = [];
        $this->toMove    = [];

        if ($move) {
            $this->move($move);
        }

        if ($archive) {
            $this->archive($archive);
        }
    }

    /**
     * Archives articles through the administrator article model.
     *
     * @param   array<int>  $pks  The article ids.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function archive(array $pks): void
    {
        self::$busy = true;

        try {
            $model = $this->app->bootComponent('com_content')->getMVCFactory()
                ->createModel('Article', 'Administrator', ['ignore_request' => true]);

            $requested = $pks;

            if (!$model->publish($pks, self::STATE_ARCHIVED)) {
                $this->warn('PLG_SYSTEM_AUTOARCHIVE_MSG_ARCHIVE_FAILED', 'Archive failed for articles ' . implode(',', $requested) . ': ' . $model->getError());

                return;
            }

            if ($pks) {
                $this->notice(Text::plural('PLG_SYSTEM_AUTOARCHIVE_MSG_ARCHIVED_N', \count($pks)));
                Log::add('Archived articles ' . implode(',', $pks), Log::INFO, 'autoarchive');
            }

            // Articles moved into the archive category by the user: redirect the old address.
            $redirects = 0;

            foreach ($pks as $pk) {
                $oldUrl = $this->oldUrls[$pk] ?? '';
                unset($this->oldUrls[$pk]);

                if ($oldUrl !== '' && $this->addRedirect($pk, $oldUrl, $this->siteUrl($pk, $this->getTarget(), $this->getLanguage($pk)))) {
                    $redirects++;
                }
            }

            $this->reportRedirects();
        } catch (\Throwable $e) {
            $this->warn('PLG_SYSTEM_AUTOARCHIVE_MSG_ARCHIVE_FAILED', 'Archive failed for articles ' . implode(',', $pks) . ': ' . $e->getMessage());
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Moves articles to the target category and reindexes them in Smart Search.
     *
     * @param   array<int>  $pks  The article ids.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function move(array $pks): void
    {
        $pks = $this->filterByCategory($pks, false);

        if (!$pks) {
            return;
        }

        $target = $this->getTarget();
        $user   = $this->app->getIdentity();

        // Only a logged-in user can trigger a move: never guests, never the command line.
        if ($this->app->isClient('cli') || !$user || $user->guest || !$user->id) {
            Log::add('Move skipped for articles ' . implode(',', $pks) . ': no logged-in user', Log::WARNING, 'autoarchive');

            return;
        }

        if ($this->params->get('require_create', 1) && !$user->authorise('core.create', 'com_content.category.' . $target)) {
            $this->warn(
                'PLG_SYSTEM_AUTOARCHIVE_MSG_MOVE_SKIPPED',
                'Move skipped for articles ' . implode(',', $pks) . ': user ' . $user->id . ' cannot create in category ' . $target
            );

            return;
        }

        self::$busy = true;
        $moved      = [];
        $skipped    = [];

        try {
            /** @var ContentTable $table */
            $table = $this->app->bootComponent('com_content')->getMVCFactory()->createTable('Article', 'Administrator');

            $redirect  = (bool) $this->params->get('redirect_on_move', 1);
            $redirects = 0;

            foreach ($pks as $pk) {
                if (!$user->authorise('core.edit.state', 'com_content.article.' . $pk)) {
                    $skipped[$pk] = 'no core.edit.state';
                    continue;
                }

                $table->reset();
                $table->id = null;

                if (!$table->load($pk)) {
                    continue;
                }

                // Do not touch an article that another user is editing.
                if (!empty($table->checked_out) && (int) $table->checked_out !== (int) $user->id) {
                    $skipped[$pk] = 'checked out by user ' . (int) $table->checked_out;
                    continue;
                }

                $oldUrl       = $redirect ? $this->siteUrl($pk, (int) $table->catid, (string) $table->language) : '';
                $table->catid = $target;

                if (!$table->check() || !$table->store()) {
                    $this->warn('PLG_SYSTEM_AUTOARCHIVE_MSG_MOVE_FAILED', 'Move failed for article ' . $pk . ': ' . $table->getError());
                    continue;
                }

                $moved[] = $pk;
                $this->reindex($table);

                if ($oldUrl !== '' && $this->addRedirect($pk, $oldUrl, $this->siteUrl($pk, $target, (string) $table->language))) {
                    $redirects++;
                }
            }

            if ($moved) {
                $this->cleanCache();
                $this->notice(Text::plural('PLG_SYSTEM_AUTOARCHIVE_MSG_MOVED_N', \count($moved)));
                Log::add('Moved articles ' . implode(',', $moved) . ' to category ' . $target, Log::INFO, 'autoarchive');
            }

            $this->reportRedirects();

            if ($skipped) {
                $detail = [];

                foreach ($skipped as $pk => $reason) {
                    $detail[] = $pk . ' (' . $reason . ')';
                }

                $this->warn('PLG_SYSTEM_AUTOARCHIVE_MSG_MOVE_SKIPPED', 'Move skipped for articles ' . implode(', ', $detail));
            }
        } catch (\Throwable $e) {
            $this->warn('PLG_SYSTEM_AUTOARCHIVE_MSG_MOVE_FAILED', 'Move failed for articles ' . implode(',', $pks) . ': ' . $e->getMessage());
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Returns the public URL (path, without domain) of an article in a given category,
     * built by the site router as in the frontend, or an empty string when the router
     * cannot build a search engine friendly URL.
     *
     * @param   int     $id        The article id.
     * @param   int     $catid     The category id.
     * @param   string  $language  The article language.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function siteUrl(int $id, int $catid, string $language): string
    {
        try {
            $url = (string) Route::link('site', RouteHelper::getArticleRoute($id, $catid, $language), false);
        } catch (\Throwable $e) {
            Log::add('URL of article ' . $id . ' not built: ' . $e->getMessage(), Log::WARNING, 'autoarchive');

            return '';
        }

        // Without SEF URLs the address does not depend on the category: nothing to redirect.
        return $url === '' || str_contains($url, 'option=com_content') ? '' : $url;
    }

    /**
     * A new query object, on Joomla 5.0 and 5.1 (no createQuery yet) as on 5.2 and later.
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @return  \Joomla\Database\QueryInterface
     *
     * @since   1.1.0
     */
    private static function query(DatabaseInterface $db)
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Which redirect store is used for the moves: the Joomla Redirects component (as in 1.0)
     * or the redirect rules of 4SEO. Chosen by the option "Store the redirects in": Automatic
     * uses 4SEO when it is installed, enabled and its redirects are on, Joomla otherwise.
     * Decided once per request.
     *
     * @return  string  'joomla' or 'forseo'
     *
     * @since   1.1.0
     */
    private function redirectBackend(): string
    {
        if ($this->backend === null) {
            $this->backend = self::resolveRedirectBackend($this->params);

            if ($this->backend === 'joomla' && (string) $this->params->get('redirect_target', 'auto') === 'forseo') {
                Log::add('Redirect store set to 4SEO but 4SEO is not installed, enabled or has its redirects off: using Joomla Redirects', Log::WARNING, 'autoarchive');
            }
        }

        return $this->backend;
    }

    /**
     * The redirect store for the given plugin parameters (see redirectBackend()).
     *
     * @param   Registry  $params  The plugin parameters.
     *
     * @return  string  'joomla' or 'forseo'
     *
     * @since   1.1.0
     */
    public static function resolveRedirectBackend(Registry $params): string
    {
        $choice = (string) $params->get('redirect_target', 'auto');

        if ($choice === 'joomla') {
            return 'joomla';
        }

        if (self::forseoRedirectsAvailable()) {
            return 'forseo';
        }

        return 'joomla';
    }

    /**
     * Whether 4SEO can serve the redirects: system plugin enabled, rules table present and the
     * redirects feature of 4SEO not switched off in its configuration.
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    public static function forseoRedirectsAvailable(): bool
    {
        try {
            if (!PluginHelper::isEnabled('system', 'forseo')) {
                return false;
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if (!\in_array(strtolower($db->getPrefix() . 'forseo_rules'), array_map('strtolower', $db->getTableList()), true)) {
                return false;
            }

            $query = self::query($db)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__forseo_config'))
                ->where($db->quoteName('scope') . ' = ' . $db->quote('redirects'))
                ->where($db->quoteName('key') . ' = ' . $db->quote('enabled'));
            $value = $db->setQuery($query)->loadResult();

            // No stored value means the 4SEO default, which is on.
            return $value === null || !\in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off', 'null'], true);
        } catch (\Throwable $e) {
            Log::add('4SEO not usable for redirects: ' . $e->getMessage(), Log::INFO, 'autoarchive');

            return false;
        }
    }

    /**
     * Stores a 301 redirect from the old to the new URL of a moved article, in 4SEO or in
     * the Joomla Redirects component (see redirectBackend()).
     *
     * @param   int     $id      The article id.
     * @param   string  $oldUrl  The URL before the move.
     * @param   string  $newUrl  The URL after the move.
     *
     * @return  bool  True when a redirect has been stored.
     *
     * @since   1.0.0
     */
    private function addRedirect(int $id, string $oldUrl, string $newUrl): bool
    {
        if ($newUrl === '' || $newUrl === $oldUrl) {
            return false;
        }

        if ($this->redirectBackend() === 'forseo') {
            $done = $this->addForseoRedirect($id, $oldUrl, $newUrl);

            if ($done !== null) {
                if ($done) {
                    $this->redirectCounts['forseo']++;
                }

                return $done;
            }

            // An address 4SEO cannot hold as a plain rule: Joomla Redirects takes it.
        }

        $done = $this->addJoomlaRedirect($id, $oldUrl, $newUrl);

        if ($done) {
            $this->redirectCounts['joomla']++;
        }

        return $done;
    }

    /**
     * Tells the administrator how many redirects were stored, and where.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function reportRedirects(): void
    {
        if ($this->redirectCounts['joomla']) {
            $this->notice(Text::plural('PLG_SYSTEM_AUTOARCHIVE_MSG_REDIRECTS_N', $this->redirectCounts['joomla']));
        }

        if ($this->redirectCounts['forseo']) {
            $this->notice(Text::plural('PLG_SYSTEM_AUTOARCHIVE_MSG_REDIRECTS_FORSEO_N', $this->redirectCounts['forseo']));
        }

        $this->redirectCounts = ['joomla' => 0, 'forseo' => 0];
    }

    /**
     * Root relative path that a 4SEO rule can hold for a URL, or null when the URL contains
     * what 4SEO reads as a wildcard or a regular expression.
     *
     * @param   string  $url  The public URL (path, as built by the router).
     *
     * @return  string|null
     *
     * @since   1.1.0
     */
    private function forseoPath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = Uri::root(true);

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, \strlen($base));
        }

        if ($path === '' || $path[0] !== '/' || str_contains($path, '{*}') || str_contains($path, '{?}') || str_contains($path, '{$')) {
            return null;
        }

        return $path;
    }

    /**
     * Whether a 4SEO rule specification is a plain address equal to the given path (case and
     * encoding as 4SEO compares them). Wildcard and regular expression specifications never match.
     *
     * @param   string  $spec  The URL specification or target of a rule.
     * @param   string  $path  The root relative path.
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    private function samePath(string $spec, string $path): bool
    {
        $spec = trim($spec);

        if ($spec === '' || $spec[0] === '~' || str_contains($spec, '{') || str_contains($spec, "\n")) {
            return false;
        }

        $spec = (string) parse_url($spec, PHP_URL_PATH);

        $left  = mb_strtolower($this->decodePath($spec));
        $right = mb_strtolower($this->decodePath($path));

        return $left === $right;
    }

    /**
     * A path with its percent encoding resolved, for comparisons.
     *
     * @param   string  $path  The path.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    private function decodePath(string $path): string
    {
        return rawurldecode($path);
    }

    /**
     * Stores the 301 redirect as a redirect rule of 4SEO (table #__forseo_rules, type 10,
     * the specification in the JSON column "rule"):
     *
     * - a rule that already points to the old address (a previous move, or one made by hand)
     *   is pointed to the new address, so visitors never go through two redirects;
     * - a rule of this plugin that starts from the new address, now a live page again, is
     *   switched off;
     * - a rule for the old address that this plugin did not create is never changed.
     *
     * @param   int     $id      The article id.
     * @param   string  $oldUrl  The URL before the move.
     * @param   string  $newUrl  The URL after the move.
     *
     * @return  bool|null  True when a rule has been stored, false when nothing was stored,
     *                     null when 4SEO cannot hold this address (Joomla Redirects is used).
     *
     * @since   1.1.0
     */
    private function addForseoRedirect(int $id, string $oldUrl, string $newUrl): ?bool
    {
        $old = $this->forseoPath($oldUrl);
        $new = $this->forseoPath($newUrl);

        if ($old === null || $new === null) {
            return null;
        }

        if (mb_strtolower($old) === mb_strtolower($new)) {
            return false;
        }

        try {
            $db      = Factory::getContainer()->get(DatabaseInterface::class);
            $now     = Factory::getDate()->toSql();
            $note    = self::REDIRECT_MARKER . ' article ' . $id . ' moved to the archive category';
            $rows    = $db->setQuery(
                self::query($db)
                    ->select($db->quoteName(['id', 'enabled', 'rule']))
                    ->from($db->quoteName('#__forseo_rules'))
                    ->where($db->quoteName('type') . ' = 10')
            )->loadObjectList();
            $ownRule = null;
            $stored  = false;

            foreach ($rows as $row) {
                $spec = json_decode((string) $row->rule, true);

                if (!\is_array($spec)) {
                    continue;
                }

                $own = $this->isOwnRedirect((string) ($spec['notes'] ?? ''));

                // The new address is a live page again: an own rule that starts from it goes off.
                if ($own && (int) $row->enabled !== 0 && $this->samePath((string) ($spec['urlSpec'] ?? ''), $new)) {
                    $spec['enabled'] = 0;
                    $this->saveForseoRule((int) $row->id, $spec, 0);
                    continue;
                }

                // The rule for the old address itself.
                if ($this->samePath((string) ($spec['urlSpec'] ?? ''), $old)) {
                    if ($own) {
                        $ownRule = $row;
                    } else {
                        Log::add('Redirect for article ' . $id . ' not stored: a 4SEO rule not created by the plugin already exists for ' . $old, Log::INFO, 'autoarchive');

                        return false;
                    }

                    continue;
                }

                // No double jump: whatever rule pointed to the old address now points to the new one.
                if ((int) $row->enabled !== 0 && $this->samePath((string) ($spec['actionRedirectTarget'] ?? ''), $old)) {
                    $spec['actionRedirectTarget'] = $new;

                    if (!$own) {
                        $spec['notes'] = trim((string) ($spec['notes'] ?? '') . ' [' . self::REDIRECT_MARKER . ' target changed from ' . $old . ' after the move of article ' . $id . ']');
                    }

                    $this->saveForseoRule((int) $row->id, $spec, (int) $row->enabled);
                    Log::add('4SEO rule ' . $row->id . ' now points to ' . $new . ' instead of ' . $old, Log::INFO, 'autoarchive');
                    $stored = true;
                }
            }

            if ($ownRule) {
                $spec                         = json_decode((string) $ownRule->rule, true);
                $spec['enabled']              = 2;
                $spec['actionRedirectType']   = 301;
                $spec['actionRedirectTarget'] = $new;
                $spec['notes']                = $note;
                $this->saveForseoRule((int) $ownRule->id, $spec, 2);
                Log::add('4SEO rule 301 ' . $old . ' -> ' . $new . ' updated (article ' . $id . ')', Log::INFO, 'autoarchive');

                return true;
            }

            $spec = [
                'type'                 => 10,
                'title'                => $this->forseoTitle($old),
                'enabled'              => 2,
                'notes'                => $note,
                'urlSpec'              => $old,
                'urlNegSpec'           => '',
                'disregardQuery'       => true,
                'disregardCase'        => true,
                'actionRedirectType'   => 301,
                'actionRedirectTarget' => $new,
                'actionReappendQuery'  => false,
            ];
            $max     = (int) $db->setQuery(
                self::query($db)->select('MAX(' . $db->quoteName('ordering') . ')')->from($db->quoteName('#__forseo_rules'))->where($db->quoteName('type') . ' = 10')
            )->loadResult();
            $columns = ['type', 'source', 'title', 'rule', 'hits', 'enabled', 'valid', 'ordering'];
            $title   = $spec['title'];
            $json    = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ten     = 10;
            $zero    = 0;
            $enabled = 2;
            $valid   = 1;
            $order   = $max + 1;
            $query   = self::query($db)
                ->insert($db->quoteName('#__forseo_rules'))
                ->columns($db->quoteName($columns))
                ->values(':type, :source, :title, :rule, :hits, :enabled, :valid, :ordering')
                ->bind(':type', $ten, ParameterType::INTEGER)
                ->bind(':source', $zero, ParameterType::INTEGER)
                ->bind(':title', $title)
                ->bind(':rule', $json)
                ->bind(':hits', $zero, ParameterType::INTEGER)
                ->bind(':enabled', $enabled, ParameterType::INTEGER)
                ->bind(':valid', $valid, ParameterType::INTEGER)
                ->bind(':ordering', $order, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            Log::add('4SEO rule 301 ' . $old . ' -> ' . $new . ' (article ' . $id . ')', Log::INFO, 'autoarchive');

            return true;
        } catch (\Throwable $e) {
            Log::add('4SEO rule for article ' . $id . ' not stored: ' . $e->getMessage(), Log::WARNING, 'autoarchive');

            // 4SEO did not take it: the Joomla Redirects component does.
            return null;
        }
    }

    /**
     * Writes the JSON specification and the enabled flag of an existing 4SEO rule.
     *
     * @param   int    $ruleId   The rule id.
     * @param   array  $spec     The decoded specification.
     * @param   int    $enabled  The value of the column "enabled" (0 off, 1 everywhere, 2 with conditions).
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function saveForseoRule(int $ruleId, array $spec, int $enabled): void
    {
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $json = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $db->setQuery(
            self::query($db)
                ->update($db->quoteName('#__forseo_rules'))
                ->set($db->quoteName('rule') . ' = :rule')
                ->set($db->quoteName('enabled') . ' = :enabled')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':rule', $json)
                ->bind(':enabled', $enabled, ParameterType::INTEGER)
                ->bind(':id', $ruleId, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * Title of a 4SEO rule (the column holds at most 190 characters).
     *
     * @param   string  $old  The old path.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    private function forseoTitle(string $old): string
    {
        return mb_substr(self::REDIRECT_MARKER . ' ' . $old, 0, 190);
    }

    /**
     * Stores a 301 redirect from the old to the new URL of a moved article in the Joomla
     * Redirect component (used by the "System - Redirect" plugin on 404 pages). An
     * existing redirect for the same old URL is updated; a redirect that starts from the
     * new URL, now a live page again, is unpublished.
     *
     * @param   int     $id      The article id.
     * @param   string  $oldUrl  The URL before the move.
     * @param   string  $newUrl  The URL after the move.
     *
     * @return  bool  True when a redirect has been stored.
     *
     * @since   1.0.0
     */
    private function addJoomlaRedirect(int $id, string $oldUrl, string $newUrl): bool
    {
        if ($newUrl === '' || $newUrl === $oldUrl) {
            return false;
        }

        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $marker = self::REDIRECT_MARKER . '%';
            $legacy = self::LEGACY_REDIRECT_MARKER . '%';
            $query  = self::query($db)
                ->update($db->quoteName('#__redirect_links'))
                ->set($db->quoteName('published') . ' = 0')
                ->where($db->quoteName('old_url') . ' = :new')
                ->where($db->quoteName('published') . ' = 1')
                ->where('(' . $db->quoteName('comment') . ' LIKE :marker OR ' . $db->quoteName('comment') . ' LIKE :legacy)')
                ->bind(':new', $newUrl)
                ->bind(':marker', $marker)
                ->bind(':legacy', $legacy);
            $db->setQuery($query)->execute();

            $table = $this->app->bootComponent('com_redirect')->getMVCFactory()->createTable('Link', 'Administrator');

            if (!$table) {
                return false;
            }

            // A redirect for the same old address that the plugin did not create (made by
            // hand or by another extension) is never touched.
            if ($table->load(['old_url' => $oldUrl]) && !$this->isOwnRedirect((string) $table->comment)) {
                Log::add('Redirect for article ' . $id . ' not stored: a redirect not created by the plugin already exists for ' . $oldUrl, Log::INFO, 'autoarchive');

                return false;
            }

            $now = Factory::getDate()->toSql();
            $table->bind([
                'old_url'       => $oldUrl,
                'new_url'       => $newUrl,
                'referer'       => '',
                'comment'       => self::REDIRECT_MARKER . ' article ' . $id . ' moved to the archive category',
                'published'     => 1,
                'header'        => 301,
                'created_date'  => $table->created_date ?: $now,
                'modified_date' => $now,
            ]);

            if (!$table->check() || !$table->store()) {
                Log::add('Redirect for article ' . $id . ' not stored: ' . $table->getError(), Log::WARNING, 'autoarchive');

                return false;
            }

            Log::add('Redirect 301 ' . $oldUrl . ' -> ' . $newUrl . ' (article ' . $id . ')', Log::INFO, 'autoarchive');

            return true;
        } catch (\Throwable $e) {
            Log::add('Redirect for article ' . $id . ' not stored: ' . $e->getMessage(), Log::WARNING, 'autoarchive');

            return false;
        }
    }

    /**
     * Whether a redirect note was written by this automation (current or legacy marker).
     *
     * @param   string  $comment  The note of the redirect.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    private function isOwnRedirect(string $comment): bool
    {
        return str_starts_with($comment, self::REDIRECT_MARKER) || str_starts_with($comment, self::LEGACY_REDIRECT_MARKER);
    }

    /**
     * Updates the Smart Search index of a moved article (category taxonomy and route).
     *
     * @param   Table  $table  The stored article table.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function reindex(Table $table): void
    {
        $dispatcher = $this->app->getDispatcher();

        PluginHelper::importPlugin('finder', null, true, $dispatcher);

        $dispatcher->dispatch('onFinderAfterSave', new AfterSaveEvent('onFinderAfterSave', [
            'context' => 'com_content.article',
            'subject' => $table,
            'isNew'   => false,
        ]));
    }

    /**
     * Returns the ids that are (or are not) in the target category.
     *
     * @param   array<int>  $pks       The article ids.
     * @param   bool        $inTarget  True to keep articles already in the target category.
     *
     * @return  array<int>
     *
     * @since   1.0.0
     */
    private function filterByCategory(array $pks, bool $inTarget): array
    {
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $target = $this->getTarget();
        $query  = self::query($db)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__content'))
            ->whereIn($db->quoteName('id'), $pks)
            ->where($db->quoteName('catid') . ($inTarget ? ' = ' : ' <> ') . ':catid')
            ->bind(':catid', $target, ParameterType::INTEGER);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    /**
     * @param   int  $id  The article id.
     *
     * @return  string  The language of the article ('*' when unknown).
     *
     * @since   1.0.0
     */
    private function getLanguage(int $id): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = self::query($db)
            ->select($db->quoteName('language'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return (string) ($db->setQuery($query)->loadResult() ?: '*');
    }

    /**
     * @return  int  The target category id.
     *
     * @since   1.0.0
     */
    private function getTarget(): int
    {
        return (int) $this->params->get('target_catid', 0);
    }

    /**
     * Checks that a category can be used as archive category: it exists, belongs to
     * com_content and is not in the trash.
     *
     * @param   int  $catid  The category id.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    public static function isValidTarget(int $catid): bool
    {
        $state = self::getTargetState($catid);

        return $state !== null && $state !== -2;
    }

    /**
     * Returns the published state of a com_content category (1 published, 0 unpublished,
     * 2 archived, -2 trashed), or null when it does not exist.
     *
     * @param   int  $catid  The category id.
     *
     * @return  int|null
     *
     * @since   1.0.0
     */
    public static function getTargetState(int $catid): ?int
    {
        static $cache = [];

        if ($catid <= 0) {
            return null;
        }

        if (\array_key_exists($catid, $cache)) {
            return $cache[$catid];
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = self::query($db)
            ->select($db->quoteName('published'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->bind(':id', $catid, ParameterType::INTEGER);

        $published = $db->setQuery($query)->loadResult();

        return $cache[$catid] = $published === null ? null : (int) $published;
    }

    /**
     * Cleans the com_content cache after a move.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function cleanCache(): void
    {
        try {
            Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'com_content'])
                ->clean();
        } catch (\Throwable $e) {
            Log::add('Cache clean failed: ' . $e->getMessage(), Log::WARNING, 'autoarchive');
        }
    }

    /**
     * Shows an information message in the administrator.
     *
     * @param   string  $message  The message.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function notice(string $message): void
    {
        if ($this->app->isClient('administrator')) {
            $this->app->enqueueMessage($message, 'info');
        }
    }

    /**
     * Logs a problem with all its details and shows only a generic, translated
     * message: in the administrator to everyone, in the frontend only to users who
     * can manage articles. Error details (ids, database messages) never reach the screen.
     *
     * @param   string  $languageKey  Language key of the generic message.
     * @param   string  $detail       Technical detail, written only to the log.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function warn(string $languageKey, string $detail): void
    {
        Log::add($detail, Log::WARNING, 'autoarchive');

        $user = $this->app->getIdentity();
        $show = $this->app->isClient('administrator')
            || ($this->app->isClient('site') && $user && $user->authorise('core.manage', 'com_content'));

        if ($show) {
            $this->app->enqueueMessage(Text::_($languageKey), 'warning');
        }
    }
}
