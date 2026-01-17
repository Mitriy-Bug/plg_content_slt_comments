<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  content.slt_comments
 *
 * @copyright   (C) 2026 www.codersite.ru
 * @license     GNU General Public License version 2 or later;
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use SLT\Plugin\Content\SltComments\Extension\SltComments;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
	            $config = (array) PluginHelper::getPlugin('content', 'slt_comments');
	            $subject = $container->get(DispatcherInterface::class);
	            $app = Factory::getApplication();

	            $plugin = new SltComments($subject, $config);
	            $plugin->setApplication($app);

	            return $plugin;
            }
        );
    }
};
