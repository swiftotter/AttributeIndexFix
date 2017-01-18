<?php
/**
 * @by SwiftOtter, Inc., 1/18/17
 * @website https://swiftotter.com
 **/

namespace SwiftOtter\AttributeIndexFix\Override\Catalog\Indexer\Eav;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;

/**
 * Per Magento 2 bug report #417 (https://github.com/magento/magento2/issues/417),
 * this file adds @maderlock's fix (as found in the mergeWithSourceModel function).
 *
 * Class Source
 * @package ClassicParts\Product\Override\Catalog\Indexer\Eav
 */
class Source extends \Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\Source
{
    /**
     * Prepare data index for indexable multiply select attributes
     *
     * @param array $entityIds the entity ids limitation
     * @param int $attributeId the attribute id limitation
     * @return $this
     */
    protected function _prepareMultiselectIndex($entityIds = null, $attributeId = null)
    {
        $connection = $this->getConnection();

        // prepare multiselect attributes
        if ($attributeId === null) {
            $attrIds = $this->_getIndexableAttributes(true);
        } else {
            $attrIds = [$attributeId];
        }

        if (!$attrIds) {
            return $this;
        }
        $productIdField = $this->getMetadataPool()->getMetadata(ProductInterface::class)->getLinkField();

        // load attribute options
        $options = [];
        $select = $connection->select()->from(
            $this->getTable('eav_attribute_option'),
            ['attribute_id', 'option_id']
        )->where('attribute_id IN(?)', $attrIds);
        $query = $select->query();
        while ($row = $query->fetch()) {
            $options[$row['attribute_id']][$row['option_id']] = true;
        }

        $options = $this->mergeWithSourceModel($attrIds, $options);

        // prepare get multiselect values query
        $productValueExpression = $connection->getCheckSql('pvs.value_id > 0', 'pvs.value', 'pvd.value');
        $select = $connection->select()->from(
            ['pvd' => $this->getTable('catalog_product_entity_varchar')],
            [$productIdField, 'attribute_id']
        )->join(
            ['cs' => $this->getTable('store')],
            '',
            ['store_id']
        )->joinLeft(
            ['pvs' => $this->getTable('catalog_product_entity_varchar')],
            "pvs.{$productIdField} = pvd.{$productIdField} AND pvs.attribute_id = pvd.attribute_id"
            . ' AND pvs.store_id=cs.store_id',
            ['value' => $productValueExpression]
        )->joinLeft(
            ['cpe' => $this->getTable('catalog_product_entity')],
            "cpe.{$productIdField} = pvd.{$productIdField}",
            ['entity_id']
        )->where(
            'pvd.store_id=?',
            $connection->getIfNullSql('pvs.store_id', \Magento\Store\Model\Store::DEFAULT_STORE_ID)
        )->where(
            'cs.store_id!=?',
            \Magento\Store\Model\Store::DEFAULT_STORE_ID
        )->where(
            'pvd.attribute_id IN(?)',
            $attrIds
        )->where(
            'cpe.entity_id IS NOT NULL'
        );

        $statusCond = $connection->quoteInto('=?', ProductStatus::STATUS_ENABLED);
        $this->_addAttributeToSelect($select, 'status', "pvd.{$productIdField}", 'cs.store_id', $statusCond);

        if ($entityIds !== null) {
            $select->where('cpe.entity_id IN(?)', $entityIds);
        }
        /**
         * Add additional external limitation
         */
        $this->_eventManager->dispatch(
            'prepare_catalog_product_index_select',
            [
                'select' => $select,
                'entity_field' => new \Zend_Db_Expr('cpe.entity_id'),
                'website_field' => new \Zend_Db_Expr('cs.website_id'),
                'store_field' => new \Zend_Db_Expr('cs.store_id')
            ]
        );

        $i = 0;
        $data = [];
        $query = $select->query();
        while ($row = $query->fetch()) {
            $values = explode(',', $row['value']);
            foreach ($values as $valueId) {
                /**
                 * SWIFTotter removed following `if` statement. The $options variable was being loaded
                 * from `eav_attribute_options` and was not in sync with the source model.
                 */
                if (isset($options[$row['attribute_id']][$valueId])) {
                    $data[] = [$row['entity_id'], $row['attribute_id'], $row['store_id'], $valueId];
                    $i++;
                    if ($i % 10000 == 0) {
                        $this->_saveIndexData($data);
                        $data = [];
                    }
                }
            }
        }

        $this->_saveIndexData($data);
        unset($options);
        unset($data);

        return $this;
    }

    /**
     * @maderlock's fix from: https://github.com/magento/magento2/issues/417#issuecomment-265146285
     *
     * @param array $attrIds
     * @param array $options
     * @return array
     */
    private function mergeWithSourceModel(array $attrIds, array $options)
    {
        // Add options from custom source models
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $select = $this->getConnection()->select()->from(
            ['ea' => $this->getTable('eav_attribute')],
            ['attribute_id','source_model']
        )->where('attribute_id IN(?)', $attrIds)
            ->where('source_model is not null');
        $query = $select->query();
        while ($row = $query->fetch()) {
            try {
                // Load custom source class and its options
                $source = $objectManager->create($row['source_model']);
                $sourceModelOptions = $source->getAllOptions();
                // Add options to list used below
                foreach ($sourceModelOptions as $o) {
                    $options[$row['attribute_id']][$o["value"]] = true;
                }
            } catch (\BadMethodCallException $e) {
                // Skip
            }
        }

        return $options;
    }
}