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
            $this->_totals = $this->getSalesEntity();
            $this->_applyHiddenTaxWorkaround($this->_salesEntity);
        } else {
            $address = $this->_salesEntity->getIsVirtual() ?
                $this->_salesEntity->getBillingAddress() : $this->_salesEntity->getShippingAddress();
            $shippingDescription = $address->getShippingDescription();
            $this->_totals = $this->getAddressSalesEntity();
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
     * getAdressSalesEntity
     *
     * @return array about the Order
     */
    protected function getAddressSalesEntity()
    {
        $ar = array (
            self::TOTAL_SUBTOTAL => $this->_salesEntity->getSubtotal(),
            self::TOTAL_TAX      => $address->getTaxAmount(),
            self::TOTAL_SHIPPING => $address->getShippingAmount(),
            self::TOTAL_DISCOUNT => abs($address->getDiscountAmount()),
        );
        $obj = new Varien_Object($ar);
        Mage::dispatchEvent('loe_paypal_address_sales_entity', array('obj' => $obj));
        return $obj->getData();
    }
    
    /**
     * getSalesEntity
     *
     * @return array about the Order
     */
    protected function getSalesEntity()
    {
        $ar = array(
            self::TOTAL_SUBTOTAL => $this->_salesEntity->getSubtotal(),
            self::TOTAL_TAX      => $this->_salesEntity->getTaxAmount(),
            self::TOTAL_SHIPPING => $this->_salesEntity->getShippingAmount(),
            self::TOTAL_DISCOUNT => abs($this->_salesEntity->getDiscountAmount()),
        );
        $obj = new Varien_Object($ar);
        Mage::dispatchEvent('loe_paypal_sales_entity', array('obj' => $obj));
        return $obj->getData();
        return 
    }
}