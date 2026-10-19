<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Mview;

/**
 * Marks the attribute of a row that names an option: an option's store labels and its swatches.
 *
 * Core's attribute save deletes and inserts the labels of every option it is given, so a save of
 * the attribute in the Admin marks the attribute whether a label changed or not, and the
 * attributes feed exports that one attribute again. A row whose option is gone adds nothing: the
 * delete of the option marks the attribute through its own subscription, and the cascade of the
 * foreign key to the option's labels and swatches fires no trigger.
 */
class AttributeOptionSubscription extends LookupSubscription
{
    /**
     * Every table it is attached to names the option in `option_id`.
     *
     * @param string $tableName
     * @return string[]
     */
    protected function lookup(string $tableName): array
    {
        return ['SELECT eao.attribute_id FROM {eav_attribute_option} eao WHERE eao.option_id', 'option_id'];
    }
}
