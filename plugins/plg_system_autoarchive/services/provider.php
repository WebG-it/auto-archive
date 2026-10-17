<?php

/**
 * @package     WebG.Plugin
 * @subpackage  System.autoarchive
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use WebG\Plugin\System\Autoarchive\Extension\Autoarchive;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the plugin with the DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(Autoarchive::class, function (Container $container) {
                $plugin = new Autoarchive((array) PluginHelper::getPlugin('system', 'autoarchive'));
                $plugin->setDispatcher($container->get(DispatcherInterface::class));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
