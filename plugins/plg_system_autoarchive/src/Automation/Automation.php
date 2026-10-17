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
        $query = $db->createQuery()
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

            if ($redirects) {
                $this->notice(Text::plural('PLG_SYSTEM_AUTOARCHIVE_MSG_REDIRECTS_N', $redirects));
            }
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

            if ($redirects) {
                $this->notice(Text::plural('PLG_SYSTEM_AUTOARCHIVE_MSG_REDIRECTS_N', $redirects));
            }

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
    private function addRedirect(int $id, string $oldUrl, string $newUrl): bool
    {
        if ($newUrl === '' || $newUrl === $oldUrl) {
            return false;
        }

        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $marker = self::REDIRECT_MARKER . '%';
            $legacy = self::LEGACY_REDIRECT_MARKER . '%';
            $query  = $db->createQuery()
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
        $query  = $db->createQuery()
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
        $query = $db->createQuery()
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
        $query = $db->createQuery()
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
