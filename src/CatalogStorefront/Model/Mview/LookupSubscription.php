<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Mview;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\DB\Ddl\TriggerFactory;
use Magento\Framework\Mview\Config;
use Magento\Framework\Mview\View\CollectionInterface;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\View\SubscriptionStatementPostprocessorInterface;
use Magento\Framework\Mview\ViewInterface;

/**
 * A trigger for a table whose rows name no entity of the view: the statement looks the entity ids
 * up from the row's value and inserts them into the changelog, and a row whose lookup finds
 * nothing adds nothing. An insert looks up the new value, a delete the old one, and an update both,
 * so a row that moves from one entity to another marks the two; an update marks them whether a
 * column changed or not. A subclass names the lookup per table.
 *
 * The subscription model must be a class: the mview configuration checks that it implements the
 * subscription interface, so a virtual type cannot carry the lookups.
 *
 * A view linked to the same table keeps the statement of the base class. The other way round,
 * core builds the statement of every view on a table with the subscription model of the view that
 * subscribes last, so a table another enabled view also subscribes gets the plain statement for
 * this view whenever that view subscribes after it: the entity column's value, not the lookup.
 */
abstract class LookupSubscription extends Subscription
{
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
     * The lookup of a subscribed table: the select of the view's entity ids up to the expression
     * the row's value is compared with, and the column of the row that holds the value. Tables are
     * named in braces.
     *
     * @param string $tableName the subscribed table, without the table prefix
     * @return string[] the select and the column
     * @throws \LogicException when the table has no lookup
     */
    abstract protected function lookup(string $tableName): array;

    /**
     * @param string $event
     * @param ViewInterface $view
     * @return string
     * @throws \LogicException when the table has no lookup
     */
    protected function buildStatement(string $event, ViewInterface $view): string
    {
        if ($view->getId() !== $this->getView()->getId()) {
            return parent::buildStatement($event, $view);
        }
        [$select, $column] = $this->lookup($this->getTableName());
        $changelog = $view->getChangelog();
        $select = preg_replace_callback(
            '/\{(\w+)\}/',
            fn(array $table): string => $this->connection->quoteIdentifier($this->resource->getTableName($table[1])),
            $select
        );
        $column = $this->connection->quoteIdentifier($column);
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
