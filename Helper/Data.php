<?php
/**
 * MagoArab OrderEnhancer Helper
 *
 * @category    MagoArab
 * @package     MagoArab_OrderEnhancer
 * @author      MagoArab Team
 * @copyright   Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderEnhancer\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const XML_PATH_ENABLE_EXCEL_EXPORT = 'order_enhancer/general/enable_excel_export';
    const XML_PATH_ENABLE_GOVERNORATE_FILTER = 'order_enhancer/general/enable_governorate_filter';
    const XML_PATH_ENABLE_PRODUCT_COLUMNS = 'order_enhancer/general/enable_product_columns';

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * Check if Excel export enhancement is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isExcelExportEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_EXCEL_EXPORT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if governorate filter is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isGovernorateFilterEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_GOVERNORATE_FILTER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if product columns are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isProductColumnsEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_PRODUCT_COLUMNS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}