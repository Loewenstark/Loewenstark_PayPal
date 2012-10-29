<?php

class Loewenstark_Paypal_Model_Cart
extends Mage_Paypal_Model_Cart
{    
    /**
     * (re)Render all items and totals
     * 
     * just the original class, $this->_totals
     */
    protected function _render()
    {
        if (!$this->_shouldRender) {
            return;
        }

        // regular items from the sales entity
        $this->_items = array();
        foreach ($this->_salesEntity->getAllItems() as $item) {
            if (!$item->getParentItem()) {
                $this->_addRegularItem($item);
            }
        }
        end($this->_items);
        $lastRegularItemKey = key($this->_items);

        // regular totals
        $shippingDescription = '';
        if ($this->_salesEntity instanceof Mage_Sales_Model_Order) {
            $shippingDescription = $this->_salesEntity->getShippingDescription();
            $this->_totals = $this->getSalesOrderEntity();
            $this->_applyHiddenTaxWorkaround($this->_salesEntity);
        } else {
            $address = $this->_salesEntity->getIsVirtual() ?
                $this->_salesEntity->getBillingAddress() : $this->_salesEntity->getShippingAddress();
            $shippingDescription = $address->getShippingDescription();
            $this->_totals = $this->getAddressSalesOrderEntity();
            $this->_applyHiddenTaxWorkaround($address);
        }
        $originalDiscount = $this->_totals[self::TOTAL_DISCOUNT];

        // arbitrary items, total modifications
        Mage::dispatchEvent('paypal_prepare_line_items', array('paypal_cart' => $this));

        // distinguish original discount among the others
        if ($originalDiscount > 0.0001 && isset($this->_totalLineItemDescriptions[self::TOTAL_DISCOUNT])) {
            $this->_totalLineItemDescriptions[self::TOTAL_DISCOUNT][] = Mage::helper('sales')->__('Discount (%s)', Mage::app()->getStore()->convertPrice($originalDiscount, true, false));
        }

        // discount, shipping as items
        if ($this->_isDiscountAsItem && $this->_totals[self::TOTAL_DISCOUNT]) {
            $this->addItem(Mage::helper('paypal')->__('Discount'), 1, -1.00 * $this->_totals[self::TOTAL_DISCOUNT],
                $this->_renderTotalLineItemDescriptions(self::TOTAL_DISCOUNT)
            );
        }
        $shippingItemId = $this->_renderTotalLineItemDescriptions(self::TOTAL_SHIPPING, $shippingDescription);
        if ($this->_isShippingAsItem && (float)$this->_totals[self::TOTAL_SHIPPING]) {
            $this->addItem(Mage::helper('paypal')->__('Shipping'), 1, (float)$this->_totals[self::TOTAL_SHIPPING],
                $shippingItemId
            );
        }

        // compound non-regular items into subtotal
        foreach ($this->_items as $key => $item) {
            if ($key > $lastRegularItemKey && $item->getAmount() != 0) {
                $this->_totals[self::TOTAL_SUBTOTAL] += $item->getAmount();
            }
        }

        $this->_validate();
        // if cart items are invalid, prepare cart for transfer without line items
        if (!$this->_areItemsValid) {
            $this->removeItem($shippingItemId);
        }

        $this->_shouldRender = false;
    }
    
    /**
     * Check the line items and totals according to PayPal business logic limitations
     */
    protected function _validate()
    {
        $this->_areItemsValid = false;
        $this->_areTotalsValid = false;

        $referenceAmount = $this->_salesEntity->geGrandTotal();

        $itemsSubtotal = 0;
        foreach ($this->_items as $i) {
            $itemsSubtotal = $itemsSubtotal + $i['qty'] * $i['amount'];
        }
        $sum = $itemsSubtotal + $this->_totals[self::TOTAL_TAX];
        if (!$this->_isShippingAsItem) {
            $sum += $this->_totals[self::TOTAL_SHIPPING];
        }
        if (!$this->_isDiscountAsItem) {
            $sum -= $this->_totals[self::TOTAL_DISCOUNT];
        }
        /**
         * numbers are intentionally converted to strings because of possible comparison error
         * see http://php.net/float
         */
        // match sum of all the items and totals to the reference amount
        if (sprintf('%.4F', $sum) == sprintf('%.4F', $referenceAmount)) {
            $this->_areItemsValid = true;
        }

        // PayPal requires to have discount less than items subtotal
        if (!$this->_isDiscountAsItem) {
            $this->_areTotalsValid = round($this->_totals[self::TOTAL_DISCOUNT], 4) < round($itemsSubtotal, 4);
        } else {
            $this->_areTotalsValid = $itemsSubtotal > 0.00001;
        }

        $this->_areItemsValid = $this->_areItemsValid && $this->_areTotalsValid;
    }
    
    /**
     * Add a usual line item with amount and qty
     *
     * @param Varien_Object $salesItem
     * @return Varien_Object
     */
    protected function _addRegularItem(Varien_Object $salesItem)
    {
        if ($this->_salesEntity instanceof Mage_Sales_Model_Order) {
            $qty = (int) $salesItem->getQtyOrdered();
            $amount = (float) $salesItem->getPrice();
            // TODO: nominal item for order
        } else {
            $qty = (int) $salesItem->getTotalQty();
            $amount = $salesItem->isNominal() ? 0 : (float) $salesItem->getCalculationPrice();
        }
        // workaround in case if item subtotal precision is not compatible with PayPal (.2)
        $subAggregatedLabel = '';
        if ($amount - round($amount, 2)) {
            $amount = $amount * $qty;
            $subAggregatedLabel = ' x' . $qty;
            $qty = 1;
        }

        // aggregate item price if item qty * price does not match row total
        if (($amount * $qty) != $salesItem->getRowTotal()) {
            $amount = (float) $salesItem->getRowTotal();
            $subAggregatedLabel = ' x' . $qty;
            $qty = 1;
        }

        return $this->addItem($salesItem->getName() . $subAggregatedLabel, $qty, $amount, $salesItem->getSku());
    }
    
    /**
     * Get/Set for the specified variable.
     * If the value changes, the re-rendering is commenced
     *
     * @param string $var
     * @param $setValue
     * @return bool|Mage_Paypal_Model_Cart
     */
    protected function _totalAsItem($var, $setValue = null)
    {
        if (null !== $setValue) {
            if ($setValue != $this->$var) {
                $this->_shouldRender = true;
            }
            $this->$var = $setValue;
            return $this;
        }
        return $this->$var;
    }
    

    
    /**
     * Add "hidden" discount and shipping tax
     *
     * Go ahead, try to understand ]:->
     *
     * Tax settings for getting "discount tax":
     * - Catalog Prices = Including Tax
     * - Apply Customer Tax = After Discount
     * - Apply Discount on Prices = Including Tax
     *
     * Test case for getting "hidden shipping tax":
     * - Make sure shipping is taxable (set shipping tax class)
     * - Catalog Prices = Including Tax
     * - Shipping Prices = Including Tax
     * - Apply Customer Tax = After Discount
     * - Create a shopping cart price rule with % discount applied to the Shipping Amount
     * - run shopping cart and estimate shipping
     * - go to PayPal
     *
     * @param Mage_Core_Model_Abstract $salesEntity
     */
    protected function _applyHiddenTaxWorkaround($salesEntity)
    {
        $this->_totals[self::TOTAL_TAX] += (float)$salesEntity->getHiddenTaxAmount();
        $this->_totals[self::TOTAL_TAX] += (float)$salesEntity->getShippingHiddenTaxAmount();
    }
    
    /**
     * getAdressSalesEntity
     *
     * @return array about the Order
     */
    protected function getAddressSalesOrderEntity()
    {
        $ar = array (
            self::TOTAL_SUBTOTAL => $this->_salesEntity->getSubtotal(),
            self::TOTAL_TAX      => $address->getTaxAmount(),
            self::TOTAL_SHIPPING => $address->getShippingAmount(),
            self::TOTAL_DISCOUNT => abs($address->getDiscountAmount()),
        );
        $obj = new Varien_Object($ar);
        Mage::dispatchEvent('loe_paypal_address_sales_entity', array('obj' => $obj, "class" => $this));
        return $obj->getData();
    }
    
    /**
     * getSalesEntity
     *
     * @return array about the Order
     */
    protected function getSalesOrderEntity()
    {
        $ar = array(
            self::TOTAL_SUBTOTAL => $this->_salesEntity->getSubtotal(),
            self::TOTAL_TAX      => $this->_salesEntity->getTaxAmount(),
            self::TOTAL_SHIPPING => $this->_salesEntity->getShippingAmount(),
            self::TOTAL_DISCOUNT => abs($this->_salesEntity->getDiscountAmount()),
        );
        $obj = new Varien_Object($ar);
        Mage::dispatchEvent('loe_paypal_sales_entity', array('obj' => $obj, "class" => $this));
        return $obj->getData();
    }
}