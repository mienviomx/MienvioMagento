<?php
namespace MienvioMagento\MienvioGeneral\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $tables = ['quote_address', 'sales_order_address'];
        foreach ($tables as $table) {
            $tableName = $setup->getTable($table);

            if (!$setup->getConnection()->tableColumnExists($tableName, 'neighborhood')) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'neighborhood',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 255,
                        'nullable' => true,
                        'comment' => 'Neighborhood'
                    ]
                );
            }

            if (!$setup->getConnection()->tableColumnExists($tableName, 'references')) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'references',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 255,
                        'nullable' => true,
                        'comment' => 'References'
                    ]
                );
            }
        }

        $setup->endSetup();
    }
}