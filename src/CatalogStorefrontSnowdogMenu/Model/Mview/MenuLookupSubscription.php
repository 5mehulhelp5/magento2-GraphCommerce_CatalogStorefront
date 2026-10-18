<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Mview;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\DB\Ddl\TriggerFactory;
use Magento\Framework\Mview\Config;
use Magento\Framework\Mview\View\CollectionInterface;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\View\SubscriptionStatementPostprocessorInterface;
use Magento\Framework\Mview\ViewInterface;

/**
 * Marks the menus of a row that names no menu: the menu of a node whose customer groups change, and
 * the menus whose CMS page or CMS block nodes name a page or block that changes.
 *
 * A row of snowmenu_customer names a node, and a node save that changes only its groups leaves its
 * row in snowmenu_node as it was. A CMS node names its page or block by identifier, and a page or
 * block save writes the page or block and its store rows only. The trigger looks the menus up in
 * snowmenu_node, compares an identifier through the collation of the columns as the node types do,
 * and adds nothing for a row whose node is gone. An update looks up the old and the new value, so a
 * changed identifier or store marks the menus of both. A view linked to the same table keeps the
 * statement of the base class.
 */
class MenuLookupSubscription extends Subscription
{
    /**
     * Per table, the select of the menu ids up to the expression the row's value is compared with,
     * and the column of the row that holds the value. Tables are named in braces.
     */
    private const LOOKUPS = [
        'snowmenu_customer' => [
            'SELECT node.menu_id FROM {snowmenu_node} node WHERE node.node_id',
            'node_id',
        ],
        'cms_page' => [
            "SELECT node.menu_id FROM {snowmenu_node} node WHERE node.type = 'cms_page' AND node.content",
            'identifier',
        ],
        'cms_page_store' => [
            "SELECT node.menu_id FROM {snowmenu_node} node JOIN {cms_page} page ON page.identifier = node.content"
            . " WHERE node.type = 'cms_page' AND page.page_id",
            'page_id',
        ],
        'cms_block' => [
            "SELECT node.menu_id FROM {snowmenu_node} node WHERE node.type = 'cms_block' AND node.content",
            'identifier',
        ],
        'cms_block_store' => [
            "SELECT node.menu_id FROM {snowmenu_node} node JOIN {cms_block} block ON block.identifier = node.content"
            . " WHERE node.type = 'cms_block' AND block.block_id",
            'block_id',
        ],
    ];

    /**
     * @param ResourceConnection $resource
     * @param TriggerFactory $triggerFactory
     * @param CollectionInterface $viewCollection
     * @param ViewInterface $view
     * @param string $tableName
     * @param string $columnName
     * @param SubscriptionStatementPostprocessorInterface $postprocessor
     * @param array $ignoredUpdateColumns
     * @param array $ignoredUpdateColumnsBySubscription
     * @param Config|null $mviewConfig
     */
    public function __construct(
        ResourceConnection $resource,
        TriggerFactory $triggerFactory,
        CollectionInterface $viewCollection,
        ViewInterface $view,
        $tableName,
        $columnName,
        private readonly SubscriptionStatementPostprocessorInterface $postprocessor,
        $ignoredUpdateColumns = [],
        $ignoredUpdateColumnsBySubscription = [],
        ?Config $mviewConfig = null,
    ) {
        parent::__construct(
            $resource,
            $triggerFactory,
            $viewCollection,
            $view,
            $tableName,
            $columnName,
            $ignoredUpdateColumns,
            $ignoredUpdateColumnsBySubscription,
            $mviewConfig,
            $postprocessor
        );
    }

    /**
     * @param string $event
     * @param ViewInterface $view
     * @return string
     * @throws \LogicException when the table has no menu lookup
     */
    protected function buildStatement(string $event, ViewInterface $view): string
    {
        if ($view->getId() !== $this->getView()->getId()) {
            return parent::buildStatement($event, $view);
        }
        $lookup = self::LOOKUPS[$this->getTableName()] ?? null;
        if ($lookup === null) {
            throw new \LogicException(sprintf('The table %s has no menu lookup', $this->getTableName()));
        }
        $changelog = $view->getChangelog();
        $select = preg_replace_callback(
            '/\{(\w+)\}/',
            fn(array $table): string => $this->connection->quoteIdentifier($this->resource->getTableName($table[1])),
            $lookup[0]
        );
        $column = $this->connection->quoteIdentifier($lookup[1]);
        $statement = sprintf(
            'INSERT INTO %s (%s) %s %s;',
            $this->connection->quoteIdentifier($this->resource->getTableName($changelog->getName())),
            $this->connection->quoteIdentifier($changelog->getColumnName()),
            $select,
            match ($event) {
                Trigger::EVENT_INSERT => '= NEW.' . $column,
                Trigger::EVENT_DELETE => '= OLD.' . $column,
                default => sprintf('IN (NEW.%1$s, OLD.%1$s)', $column),
            }
        );

        return $this->postprocessor->process($this->getTableName(), $event, $statement);
    }
}
