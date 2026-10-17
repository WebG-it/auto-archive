<?php

/**
 * @package     WebG.Plugin
 * @subpackage  System.autoarchive
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Installation script: checks PHP and Joomla versions, enables the plugin on the first
 * install and, when Archive Layouts for YOOtheme 1.x was running the archive automation,
 * imports its settings and switches that automation off so it does not run twice.
 *
 * @since  1.0.0
 */
class PlgSystemAutoarchiveInstallerScript extends InstallerScript
{
    /**
     * @var    string
     * @since  1.0.0
     */
    protected $minimumPhp = '8.1.0';

    /**
     * @var    string
     * @since  1.0.0
     */
    protected $minimumJoomla = '5.0.0';

    /**
     * Automation settings that Archive Layouts for YOOtheme 1.x stored in its parameters
     * (the names are the same in this plugin).
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const IMPORT_KEYS = [
        'automation',
        'target_catid',
        'require_create',
        'move_on_archive',
        'redirect_on_move',
        'archive_on_move',
        'force_archived',
    ];

    /**
     * @param   string            $type    install, update or discover_install.
     * @param   InstallerAdapter  $parent  The installer adapter.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    public function postflight($type, $parent)
    {
        if ($type !== 'install' && $type !== 'discover_install') {
            return true;
        }

        Factory::getApplication()->getLanguage()->load('plg_system_autoarchive.sys', JPATH_PLUGINS . '/system/autoarchive');

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Switched on at the first install; the automation itself stays off until it is configured.
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('autoarchive'));
        $db->setQuery($query)->execute();

        $this->importFromYooarchive($db);

        return true;
    }

    /**
     * Copies the automation settings of Archive Layouts for YOOtheme 1.x, if that plugin is
     * installed and was configured. When the automation was on there, it is switched off in
     * the old plugin (only while that plugin is still a 1.x version, which carries the
     * automation code) so that the two never process the same article.
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function importFromYooarchive(DatabaseInterface $db): void
    {
        $old = $this->getExtension($db, 'yooarchive');

        if (!$old) {
            return;
        }

        $oldParams = $this->registry($old->params);
        $import    = [];

        foreach (self::IMPORT_KEYS as $key) {
            if ($oldParams->exists($key)) {
                $import[$key] = (int) $oldParams->get($key);
            }
        }

        if (empty($import['target_catid']) && empty($import['automation'])) {
            return;
        }

        $new = $this->getExtension($db, 'autoarchive');

        if (!$new) {
            return;
        }

        $newParams  = $this->registry($new->params);
        $oldVersion = (string) $this->registry($old->manifest_cache)->get('version', '');
        $isLegacy   = $oldVersion !== '' && version_compare($oldVersion, '2.0.0', '<');
        $app        = Factory::getApplication();

        // A site that already configured Auto Archive keeps its own settings.
        $configured = (int) $newParams->get('target_catid', 0) > 0 || (int) $newParams->get('automation', 0) === 1;

        if (!$configured) {
            foreach ($import as $key => $value) {
                $newParams->set($key, $value);
            }

            $this->saveParams($db, (int) $new->extension_id, $newParams);
        }

        if (!empty($import['automation'])) {
            // The old plugin (1.x still carries the automation code) must not run it as well.
            if ($isLegacy) {
                $oldParams->set('automation', 0);
                $this->saveParams($db, (int) $old->extension_id, $oldParams);
            }

            if (!$configured && (int) ($import['target_catid'] ?? 0) > 0) {
                $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_AUTOARCHIVE_IMPORTED', (int) $import['target_catid']), 'warning');
            } elseif (!$configured) {
                $app->enqueueMessage(Text::_('PLG_SYSTEM_AUTOARCHIVE_IMPORTED_OFF'), 'notice');
            }

            return;
        }

        if (!$configured) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_AUTOARCHIVE_IMPORTED_OFF'), 'notice');
        }
    }

    /**
     * @param   string|null  $json  Stored JSON.
     *
     * @return  Registry  Empty when the JSON is not valid.
     *
     * @since   1.0.0
     */
    private function registry(?string $json): Registry
    {
        try {
            return new Registry($json ?: '{}');
        } catch (\Throwable $e) {
            return new Registry();
        }
    }

    /**
     * @param   DatabaseInterface  $db       The database.
     * @param   string             $element  The plugin element.
     *
     * @return  object|null  extension_id, params and manifest_cache of the system plugin.
     *
     * @since   1.0.0
     */
    private function getExtension(DatabaseInterface $db, string $element): ?object
    {
        $query = $db->createQuery()
            ->select($db->quoteName(['extension_id', 'params', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':element', $element);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * @param   DatabaseInterface  $db      The database.
     * @param   int                $id      The extension id.
     * @param   Registry           $params  The parameters to store.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function saveParams(DatabaseInterface $db, int $id, Registry $params): void
    {
        $json  = $params->toString();
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('extension_id') . ' = :id')
            ->bind(':params', $json)
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }
}
