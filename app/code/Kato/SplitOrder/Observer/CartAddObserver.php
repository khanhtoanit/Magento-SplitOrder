<?php

namespace Kato\SplitOrder\Observer;

use Kato\SplitOrder\Observer\AbstractCartActionObserver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CartAddObserver extends AbstractCartActionObserver implements ObserverInterface
{

    /**
     * Fired by sales_quote_product_add_after event
     *
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer)
    {
        $items = $observer->getEvent()->getItems();
        $this->_processQuoteItemsChange($items);
        return $this;
    }
}
