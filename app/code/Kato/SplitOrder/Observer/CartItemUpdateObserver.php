<?php

namespace Kato\SplitOrder\Observer;

use Kato\SplitOrder\Observer\AbstractCartActionObserver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CartItemUpdateObserver extends AbstractCartActionObserver implements ObserverInterface
{

    /**
     * Fired by checkout_cart_product_update_after event
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        $item = $observer->getEvent()->getQuoteItem();
        $this->_processQuoteItemsChange([$item]);
        return $this;
    }
}
