<?php

namespace Kato\SplitOrder\Observer;

use Klarna\Kp\Api\QuoteRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\GuestCartManagementInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as ItemCollection;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\QuoteFactory;
use Psr\Log\LoggerInterface;

abstract class AbstractCartActionObserver
{
    /**
     * @var QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var GuestCartManagementInterface
     */
    private $guestCartManagement;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var CartItemInterface[]
     */
    private $items = [];

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var CartItemInterfaceFactory
     */
    private $cartItemFactory;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    protected $quoteManagement;
    protected $quoteFactory;
    protected $quoteRepository;

    /**
     * @param CheckoutSession $checkoutSession
     * @param CartItemInterfaceFactory $cartItemFactory
     * @param CartManagementInterface $cartManagement
     * @param GuestCartManagementInterface $guestCartManagement
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param Registry $registry
     * @param CollectionFactory $productCollectionFactory
     * @param QuoteManagement $quoteManagement
     * @param QuoteFactory $quoteFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        CartItemInterfaceFactory $cartItemFactory,
        CartManagementInterface $cartManagement,
        GuestCartManagementInterface $guestCartManagement,
        CartRepositoryInterface $cartRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        Registry $registry,
        CollectionFactory $productCollectionFactory,
        QuoteManagement $quoteManagement,
        QuoteFactory $quoteFactory,
        QuoteRepositoryInterface $quoteRepository,
        LoggerInterface $logger
    ) {
        $this->cartItemFactory = $cartItemFactory;
        $this->cartManagement = $cartManagement;
        $this->guestCartManagement = $guestCartManagement;
        $this->cartRepository = $cartRepository;
        $this->checkoutSession = $checkoutSession;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->registry = $registry;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->quoteManagement = $quoteManagement;
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
    }

    /**
     * Fired by checkout_cart_product_update_after event
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     * @return $this
     */
    protected function _processQuoteItemsChange($items) {

        $productsToAdd = [];
        $productsToRemove = [];

        foreach ($items as $quoteItem) {
            if ($quoteItem->getParentItem()) {
                continue;
            }
            $oldQty = isset($lastValues[$quoteItem->getId()]) ? $lastValues[$quoteItem->getId()] : 0;
            $qty = $quoteItem->isDeleted() ? 0 : $quoteItem->getQty();
            if ($qty > $oldQty) {
                $productsToAdd[] = [
                    'product' => $quoteItem->getProduct()->getSku(),
                    'quantity' => $qty - $oldQty
                ];
            } elseif ($qty < $oldQty) {
                $productsToRemove[] = [
                    'product' => $quoteItem->getProduct()->getSku(),
                    'quantity' => $oldQty - $qty
                ];
            }
        }

        /** @var \Magento\Quote\Model\Quote  */
        $quote = $this->checkoutSession->getQuote();

        $this->logger->info("Number of items in current quote: " . count($quote->getAllItems()));

        $isSplit = false;
        //@TODO Create config for list all SKU will be split condition
        $targetSku = ['311110020'];
        $numberOfOrders = 2;
        $otherItems = [];
        $splitItems = [];

        foreach ($quote->getAllItems() as $quoteItem) {
            if ($quoteItem->getParentItem()) {
                continue;
            }
            $qty = $quoteItem->isDeleted() ? 0 : $quoteItem->getQty();
            $sku = $quoteItem->getProduct()->getSku();

            //$this->logger->info("Items: " . $sku . ". Qty: " . $qty);
            if (in_array($sku, $targetSku) && $qty > 5) {
                $isSplit = true;
                // Split the quantity into two nearly equal parts
                $splitQty1 = floor($qty / $numberOfOrders);
                $splitQty2 = $qty - $splitQty1;

                // Clone the item and set the quantities
                $item1 = clone $quoteItem;
                $item1->setQty($splitQty1);

                $item2 = clone $quoteItem;
                $item2->setQty($splitQty2);

                // Store the split items
                $splitItems[] = $item1;
                $splitItems[] = $item2;
            } else {
                // Add other items unchanged to the original quote
                $otherItems[] = clone $quoteItem;
            }
        }

        if ($isSplit) {
            try {
                // Save current quote
                $quote->setIsActive(false);
                $this->cartRepository->save($quote);

                $customerId = $quote->getCustomerId();

                if ($customerId) {
                    $cartId = $this->cartManagement->createEmptyCartForCustomer($customerId);
                    $cart = $this->cartRepository->get($cartId);
                } else {
                    $quoteMaskedId = $this->guestCartManagement->createEmptyCart();

                    $this->registry->register('new_cart_mask_id', $quoteMaskedId);

                    $quoteIdMask = $this->quoteIdMaskFactory->create();
                    $quoteIdMask->load($quoteMaskedId, 'masked_id');
                    $cartId = $quoteIdMask->getQuoteId();
                    $this->checkoutSession->setQuoteId($cartId);
                    $cart = $this->checkoutSession->getQuote();
                }

                // First quote
                $quote1 = clone $quote;

                // Second quote
                $quote2 = $this->quoteFactory->create();
                $quote2->setStore($quote->getStore());
                $quote2->assignCustomer($quote->getCustomer());

                // Add one split item to each new quote
                $quote1->addItem($splitItems[0]);
                $quote2->addItem($splitItems[1]);

                // Add other items to both new quotes
                foreach ($otherItems as $otherItem) {
                    $quote1->addItem(clone $otherItem);
                    $quote2->addItem(clone $otherItem);
                }

                // Save and create orders from the new quotes
                $quote1->collectTotals();
                //$quote1->save();
                $this->quoteRepository->save($quote1);
                $order1 = $this->quoteManagement->submit($quote1);

                $quote2->collectTotals();
                //$quote2->save();
                $this->quoteRepository->save($quote2);
                $order2 = $this->quoteManagement->submit($quote2);

                $this->cartRepository->save($quote1);
                $this->cartRepository->save($quote2);

                $this->logger->info("Order 1 created with qty: {$splitItems[0]->getQty()} for SKU: $targetSku");
                $this->logger->info("Order 2 created with qty: {$splitItems[1]->getQty()} for SKU: $targetSku");

                // Remove the original item from the original quote to avoid duplication
                foreach ($quote->getAllItems() as $item) {
                    if ($item->getSku() === $targetSku && $item->getQty() > 5) {
                        $quote->removeItem($item->getId());
                    }
                }

                // Recalculate totals for the original quote
                $quote->collectTotals();
                $quote->save();
            }
            catch (\Exception $e) {
                $this->logger->info($e->getMessage());
            }
        }
    }

    /**
     * Add collections of order items to cart.
     *
     * @param ItemCollection $orderItems
     * @return void
     * @throws LocalizedException
     */
    private function addItemsToCart($orderItems): void
    {
        $orderItemProductIds = [];
        $orderItemsByProductId = $orderItems;

        foreach ($orderItems as $item) {
            if (!$item->getAvailableToCheckout()) {
                if ($item->getParentItem() === null) {
                    $orderItemProductIds[] = $item->getProductId();
                    $orderItemsByProductId[$item->getProductId()][$item->getId()] = $item;
                }
            }
        }

        $products = $this->getOrderProducts($orderItemProductIds);

        // compare founded products and throw an error if some product not exists
        $productsNotFound = array_diff($orderItemProductIds, array_keys($products));
        if (!empty($productsNotFound)) {
            foreach ($productsNotFound as $productId) {
                $this->logger->debug(__('Could not find a product with ID "%1"', $productId));
            }
        }

        foreach ($orderItems as $item) {
            if (!isset($products[$item->getProductId()])) {
                continue;
            }
            $product = $products[$item->getProductId()];
            if (!$product) {
                $this->logger->debug(__('Could not find a product with ID "%1"', $productId));
            }
            $this->addItemToCart($item, $product);
        }
    }

    /**
     * Get order products by store id and order item product ids.
     *
     * @param int[] $orderItemProductIds
     * @return array
     * @throws LocalizedException
     */
    private function getOrderProducts(array $orderItemProductIds): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($orderItemProductIds)
            ->addStoreFilter()
            ->addAttributeToSelect('*')
            ->joinAttribute('status', 'catalog_product/status', 'entity_id', null, 'inner')
            ->joinAttribute('visibility', 'catalog_product/visibility', 'entity_id', null, 'inner')
            ->addOptionsToResult();

        return $collection->getItems();
    }

    /**
     * Adds order item product to cart.
     *
     * @param CartItemInterface $orderItem
     * @param $product
     * @return SalesModelServiceQuoteSubmitSuccess
     */
    private function addItemToCart($orderItem, $product)
    {
        /** @var CartItemInterface $cartItem */
        $cartItem = $this->cartItemFactory->create();
        $cartItem->setSku($product->getSku());
        $cartItem->setName($orderItem->getName());
        $cartItem->setQty($orderItem->getQty());
        $cartItem->setPrice($orderItem->getPrice());
        $cartItem->setAvailableToCheckout(1);
        $cartItem->setProductType($orderItem->getProductType());

        if ($orderItem->getProductOption()) {
            $cartItem->setProductOption($orderItem->getProductOption());
        }

        $this->items[] = $cartItem;
        return $this;
    }
}
