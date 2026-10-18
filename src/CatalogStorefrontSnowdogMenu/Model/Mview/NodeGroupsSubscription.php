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
 * Marks the menu of a node whose customer groups change.
 *
 * A row of snowmenu_customer names a node, not its menu, and a node save that changes only the groups
 * of the node leaves its row in snowmenu_node as it was. The trigger looks the menu up in
 * snowmenu_node and adds nothing for a node that is gone, whose delete marked its menu already. A view
 * linked to the same table keeps the statement of the base class.
 */
class NodeGroupsSubscription extends Subscription
{
    private const NODE_TABLE = 'snowmenu_node';

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
     */
    protected function buildStatement(string $event, ViewInterface $view): string
    {
        if ($view->getId() !== $this->getView()->getId()) {
            return parent::buildStatement($event, $view);
        }
        $changelog = $view->getChangelog();
        $statement = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s WHERE %s = %s%s;',
            $this->connection->quoteIdentifier($this->resource->getTableName($changelog->getName())),
            $this->connection->quoteIdentifier($changelog->getColumnName()),
            $this->connection->quoteIdentifier('menu_id'),
            $this->connection->quoteIdentifier($this->resource->getTableName(self::NODE_TABLE)),
            $this->connection->quoteIdentifier('node_id'),
            $event === Trigger::EVENT_DELETE ? 'OLD.' : 'NEW.',
            $this->connection->quoteIdentifier($this->getColumnName())
        );

        return $this->postprocessor->process($this->getTableName(), $event, $statement);
    }
}
