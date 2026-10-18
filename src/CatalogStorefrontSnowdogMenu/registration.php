<?php
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

// The plugins, the feed and the triggers name Snowdog's classes and tables, so the module exists only
// where Snowdog_Menu's code is installed. Composer registers its class loader before it includes this file.
if (interface_exists(\Snowdog\Menu\Api\MenuRepositoryInterface::class)) {
    ComponentRegistrar::register(ComponentRegistrar::MODULE, 'GraphCommerce_CatalogStorefrontSnowdogMenu', __DIR__);
}
