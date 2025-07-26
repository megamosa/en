<?php
/**
 * MagoArab OrderEnhancer Order Grid Plugin
 *
 * @category    MagoArab
 * @package     MagoArab_OrderEnhancer
 * @author      MagoArab Team
 * @copyright   Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderEnhancer\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection;
use MagoArab\OrderEnhancer\Helper\Data as HelperData;

class OrderGridPlugin
{
    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * @param HelperData $helperData
     */
    public function __construct(HelperData $helperData)
    {
        $this->helperData = $helperData;
    }

    /**
     * Before load to add product columns
     *
     * @param Collection $subject
     * @param bool $printQuery
     * @param bool $logQuery
     * @return array
     */
    public function beforeLoad(Collection $subject, $printQuery = false, $logQuery = false)
    {
        if (!$this->helperData->isProductColumnsEnabled()) {
            return [$printQuery, $logQuery];
        }

        if (!$subject->isLoaded()) {
            $this->addProductColumns($subject);
        }
        
        return [$printQuery, $logQuery];
    }

    /**
     * Add product columns to order grid
     *
     * @param Collection $collection
     */
    protected function addProductColumns(Collection $collection)
    {
        $select = $collection->getSelect();
        
        // Reset any existing joins and start fresh
        $select->reset(\Zend_Db_Select::COLUMNS);
        
        // Add main table columns first
        $select->columns([
            'entity_id' => 'main_table.entity_id',
            'status' => 'main_table.status',
            'store_id' => 'main_table.store_id',
            'store_name' => 'main_table.store_name',
            'customer_id' => 'main_table.customer_id',
            'base_grand_total' => 'main_table.base_grand_total',
            'base_total_paid' => 'main_table.base_total_paid',
            'grand_total' => 'main_table.grand_total',
            'total_paid' => 'main_table.total_paid',
            'increment_id' => 'main_table.increment_id',
            'base_currency_code' => 'main_table.base_currency_code',
            'order_currency_code' => 'main_table.order_currency_code',
            'shipping_name' => 'main_table.shipping_name',
            'billing_name' => 'main_table.billing_name',
            'created_at' => 'main_table.created_at',
            'updated_at' => 'main_table.updated_at',
            'billing_address' => 'main_table.billing_address',
            'shipping_address' => 'main_table.shipping_address',
            'shipping_information' => 'main_table.shipping_information',
            'customer_email' => 'main_table.customer_email',
            'customer_group' => 'main_table.customer_group',
            'subtotal' => 'main_table.subtotal',
            'shipping_and_handling' => 'main_table.shipping_and_handling',
            'customer_name' => 'main_table.customer_name',
            'payment_method' => 'main_table.payment_method',
            'total_refunded' => 'main_table.total_refunded'
        ]);
        
        // Add subquery for governorate from sales_order_address
        $governorateSubquery = $collection->getConnection()->select()
            ->from(
                ['soa' => $collection->getTable('sales_order_address')],
                [new \Zend_Db_Expr('COALESCE(
                    NULLIF(TRIM(soa.region), ""),
                    NULLIF(TRIM(soa.city), ""),
                    "غير محدد"
                )')]
            )
            ->where('soa.parent_id = main_table.entity_id')
            ->where('soa.address_type = "shipping"')
            ->limit(1);
            
        // Fallback subquery for billing if shipping not found
        $governorateFallback = $collection->getConnection()->select()
            ->from(
                ['soa_b' => $collection->getTable('sales_order_address')],
                [new \Zend_Db_Expr('COALESCE(
                    NULLIF(TRIM(soa_b.region), ""),
                    NULLIF(TRIM(soa_b.city), ""),
                    "غير محدد"
                )')]
            )
            ->where('soa_b.parent_id = main_table.entity_id')
            ->where('soa_b.address_type = "billing"')
            ->limit(1);
            
        $select->columns([
            'governorate' => new \Zend_Db_Expr('COALESCE((' . $governorateSubquery . '), (' . $governorateFallback . '), "غير محدد")')
        ]);
        
        // Add phone number from billing address
        $phoneSubquery = $collection->getConnection()->select()
            ->from(
                ['soa_phone' => $collection->getTable('sales_order_address')],
                ['telephone']
            )
            ->where('soa_phone.parent_id = main_table.entity_id')
            ->where('soa_phone.address_type = "billing"')
            ->limit(1);
            
        $select->columns([
            'customer_phone' => new \Zend_Db_Expr('(' . $phoneSubquery . ')')
        ]);
        
        // Add customer note from sales_order table
        $customerNoteSubquery = $collection->getConnection()->select()
            ->from(
                ['so' => $collection->getTable('sales_order')],
                ['customer_note']
            )
            ->where('so.entity_id = main_table.entity_id')
            ->limit(1);
            
        $select->columns([
            'customer_note' => new \Zend_Db_Expr('(' . $customerNoteSubquery . ')')
        ]);
        
        // Add subquery for product data from sales_order_item
        $productsSubquery = $collection->getConnection()->select()
            ->from(
                ['soi' => $collection->getTable('sales_order_item')],
                [new \Zend_Db_Expr('GROUP_CONCAT(soi.name SEPARATOR " | ")')]
            )
            ->where('soi.order_id = main_table.entity_id')
            ->where('soi.parent_item_id IS NULL');
            
        $skusSubquery = $collection->getConnection()->select()
            ->from(
                ['soi' => $collection->getTable('sales_order_item')],
                [new \Zend_Db_Expr('GROUP_CONCAT(soi.sku SEPARATOR " | ")')]
            )
            ->where('soi.order_id = main_table.entity_id')
            ->where('soi.parent_item_id IS NULL');
            
        $countSubquery = $collection->getConnection()->select()
            ->from(
                ['soi' => $collection->getTable('sales_order_item')],
                [new \Zend_Db_Expr('CONCAT(COUNT(soi.item_id), " منتج (", CAST(SUM(soi.qty_ordered) AS SIGNED), " قطعة)")')]
            )
            ->where('soi.order_id = main_table.entity_id')
            ->where('soi.parent_item_id IS NULL');
            
        $select->columns([
            'product_names' => new \Zend_Db_Expr('(' . $productsSubquery . ')'),
            'product_skus' => new \Zend_Db_Expr('(' . $skusSubquery . ')'),
            'total_products_ordered' => new \Zend_Db_Expr('(' . $countSubquery . ')')
        ]);
    }
}