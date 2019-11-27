<?php

$installer = $this;
/* @var $installer Mage_Catalog_Model_Resource_Setup */

$installer->startSetup();

$tableName = $installer->getTable('sales/order');

$table = $installer->getConnection();

$table->addColumn($tableName, 'billplz_bill_id', [
    'type' => Varien_Db_Ddl_Table::TYPE_TEXT,
    'length' => 20,
    'nullable' => true,
    'default' => null,
    'comment' => 'Billplz Bill ID',
]);

$table->addIndex($tableName, 'billplz_unique_index', 'billplz_bill_id', Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE);

$installer->endSetup();
