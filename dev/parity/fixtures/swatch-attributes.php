<?php
/**
 * Two swatch attributes for the swatch queries: `gc_swatch_color` (visual: two colours and an
 * image) and `gc_swatch_size` (text, with a store view label on one option), filterable and in
 * every attribute set of the products they go on, with a value on the first 24 enabled visible
 * products. Run from the Magento root, then reindex the product attributes feed, the products
 * feed and the search index and flush the cache (the GraphQL schema changes). Does nothing when
 * the attributes exist.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');
$eavConfig = $objectManager->get(EavConfig::class);

if ($eavConfig->getAttribute(Product::ENTITY, 'gc_swatch_color')->getId()) {
    echo "The swatch attributes exist\n";
    exit(0);
}

$image = '/g/c/gc_swatch_pattern.png';
$media = $objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
$png = imagecreatetruecolor(32, 32);
imagefill($png, 0, 0, imagecolorallocate($png, 20, 120, 60));
ob_start();
imagepng($png);
$media->writeFile('attribute/swatch' . $image, (string)ob_get_clean());

$storeId = (int)$objectManager->get(StoreManagerInterface::class)->getDefaultStoreView()->getId();
$common = [
    'entity_type_id' => $eavConfig->getEntityType(Product::ENTITY)->getId(),
    'frontend_input' => 'select',
    'backend_type' => 'int',
    'is_user_defined' => 1,
    'is_global' => 1,
    'is_filterable' => 1,
    'is_filterable_in_search' => 1,
    'is_visible_on_front' => 1,
    'used_in_product_listing' => 1,
    'is_comparable' => 1,
];
$attributes = [
    'gc_swatch_color' => $common + [
        'frontend_label' => ['GC Swatch Color'],
        'swatch_input_type' => 'visual',
        'update_product_preview_image' => 0,
        'use_product_image_for_swatch' => 0,
        'optionvisual' => [
            'value' => ['option_0' => ['Red'], 'option_1' => ['Blue'], 'option_2' => ['Pattern']],
            'order' => ['option_0' => 1, 'option_1' => 2, 'option_2' => 3],
        ],
        'swatchvisual' => ['value' => ['option_0' => '#ff0000', 'option_1' => '#0000ff', 'option_2' => $image]],
    ],
    'gc_swatch_size' => $common + [
        'frontend_label' => ['GC Swatch Size'],
        'swatch_input_type' => 'text',
        'optiontext' => [
            'value' => ['option_0' => ['Small'], 'option_1' => ['Large']],
            'order' => ['option_0' => 1, 'option_1' => 2],
        ],
        'swatchtext' => ['value' => ['option_0' => [0 => 'S', $storeId => 'Sm'], 'option_1' => [0 => 'L']]],
    ],
];

$products = $objectManager->get(CollectionFactory::class)->create()
    ->addAttributeToFilter('status', 1)
    ->addAttributeToFilter('visibility', ['in' => [2, 3, 4]])
    ->setOrder('entity_id', 'ASC')
    ->setPageSize(24);
$ids = array_map('intval', $products->getColumnValues('entity_id'));
$setIds = array_unique(array_map('intval', $products->getColumnValues('attribute_set_id')));

$management = $objectManager->get(ProductAttributeManagementInterface::class);
$optionIds = [];
foreach ($attributes as $code => $data) {
    $attribute = $objectManager->create(Attribute::class);
    $attribute->setData($data + ['attribute_code' => $code])->save();
    foreach ($setIds as $setId) {
        $groupId = (int)$objectManager->get(SetFactory::class)->create()->load($setId)->getDefaultGroupId();
        $management->assign($setId, $groupId, $code, 900);
    }
    $eavConfig->clear();
    $optionIds[$code] = array_values(array_filter(array_map(
        static fn($option) => (int)$option->getValue(),
        $eavConfig->getAttribute(Product::ENTITY, $code)->getOptions()
    )));
}

$action = $objectManager->get(Action::class);
foreach ($ids as $index => $id) {
    $action->updateAttributes([$id], [
        'gc_swatch_color' => $optionIds['gc_swatch_color'][$index % count($optionIds['gc_swatch_color'])],
        'gc_swatch_size' => $optionIds['gc_swatch_size'][$index % count($optionIds['gc_swatch_size'])],
    ], 0);
}
echo sprintf("gc_swatch_color and gc_swatch_size on %d products\n", count($ids));
