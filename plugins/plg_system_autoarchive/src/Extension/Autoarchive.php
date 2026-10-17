<?php

/**
 * @package     WebG.Plugin
 * @subpackage  System.autoarchive
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\System\Autoarchive\Extension;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use WebG\Plugin\System\Autoarchive\Automation\Automation;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * System plugin "Auto Archive".
 *
 * Keeps the archived articles of a Joomla site in one chosen category: an archived article
 * is moved there, and a published article saved or moved (also by batch) into that category
 * is archived through the article model. Plain Joomla: it works with or without YOOtheme.
 *
 * @since  1.0.0
 */
final class Autoarchive extends CMSPlugin implements SubscriberInterface
{
    /**
     * Lazily created automation service.
     *
     * @var    Automation|null
     * @since  1.0.0
     */
    private ?Automation $automation = null;

    /**
     * Cached result of the archive category check (null = not checked yet).
     *
     * @var    bool|null
     * @since  1.0.0
     */
    private ?bool $validTarget = null;

    /**
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'    => 'onAfterInitialise',
            'onTableBeforeStore'   => 'onTableBeforeStore',
            'onTableAfterStore'    => 'onTableAfterStore',
            'onContentChangeState' => 'onContentChangeState',
            'onContentAfterSave'   => 'onFlush',
            'onContentCleanCache'  => 'onFlush',
        ];
    }

    /**
     * Loads the plugin language and warns the administrators about configuration problems
     * (archive category missing, trashed or unpublished; redirects on but the core
     * "System - Redirect" plugin off), only on the screens where they can fix them.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterInitialise(): void
    {
        $this->loadLanguage();

        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            $this->warnAdministrators();
        }
    }

    /**
     * @return  void
     *
     * @since   1.0.0
     */
    private function warnAdministrators(): void
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();

        if (!$user || !$user->authorise('core.manage', 'com_plugins') || !$this->params->get('automation')) {
            return;
        }

        if (!$this->hasValidTarget()) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_AUTOARCHIVE_MSG_TARGET_INVALID'), 'warning');
        } elseif (Automation::getTargetState((int) $this->params->get('target_catid', 0)) !== 1) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_AUTOARCHIVE_MSG_TARGET_UNPUBLISHED'), 'warning');
        }

        $option = $app->getInput()->getCmd('option');

        // The redirects stored after a move are served by the core "System - Redirect" plugin.
        if (
            \in_array($option, ['com_plugins', 'com_redirect'], true)
            && ($this->params->get('move_on_archive', 1) || $this->params->get('archive_on_move', 1))
            && $this->params->get('redirect_on_move', 1) && !PluginHelper::isEnabled('system', 'redirect')
        ) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_AUTOARCHIVE_MSG_REDIRECT_PLUGIN_OFF'), 'warning');
        }
    }

    /**
     * @param   Event  $event  The onTableBeforeStore event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onTableBeforeStore(Event $event): void
    {
        if ($automation = $this->getAutomation()) {
            $automation->beforeStore($event->getArgument('subject'));
        }
    }

    /**
     * @param   Event  $event  The onTableAfterStore event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onTableAfterStore(Event $event): void
    {
        if ($automation = $this->getAutomation()) {
            $automation->afterStore($event->getArgument('subject'), (bool) $event->getArgument('result'));
        }
    }

    /**
     * @param   Event  $event  The onContentChangeState event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentChangeState(Event $event): void
    {
        if ($automation = $this->getAutomation()) {
            $automation->changeState(
                (string) $event->getArgument('context'),
                (array) $event->getArgument('subject'),
                (int) $event->getArgument('value')
            );
        }
    }

    /**
     * Runs the queued archive operations once the current save or batch is complete.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onFlush(): void
    {
        if ($automation = $this->getAutomation()) {
            $automation->flush();
        }
    }

    /**
     * Whether the archive category exists, belongs to com_content and is not trashed.
     * Checked once per request.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    private function hasValidTarget(): bool
    {
        return $this->validTarget ??= Automation::isValidTarget((int) $this->params->get('target_catid', 0));
    }

    /**
     * Returns the automation service, or null when the automation is switched off.
     *
     * @return  Automation|null
     *
     * @since   1.0.0
     */
    private function getAutomation(): ?Automation
    {
        if (!$this->params->get('automation') || !$this->hasValidTarget()) {
            return null;
        }

        return $this->automation ??= new Automation($this->params, $this->getApplication());
    }
}
