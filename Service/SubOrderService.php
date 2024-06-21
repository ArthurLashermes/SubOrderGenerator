<?php

namespace SubOrderGenerator\Service;

use DateTime;
use Exception;
use SubOrderGenerator\Model\SubOrder;
use SubOrderGenerator\Model\SubOrderQuery;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;

use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\Map\OrderProductAttributeCombinationTableMap;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductAttributeCombination;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductSaleElementsQuery;

class SubOrderService
{
    public const CART_ITEM_FROM_ORDER_SESSION_KEY = 'cart_item_from_order';
    public const PICKUP_LABEL_ADDRESS = 'temp_pickup_label_address';
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EventDispatcherInterface $dispatcher,
    )
    {
    }

    const PRODUCT_REF_ALREADY_PAID = "ALREADY_PAID";
    public function createSubOrderFromParent(array $data): SubOrder{

        try {
            $parentOrder = OrderQuery::create()->findOneById($data['parentOrderId']);
            $childOrder = $this->createChildOrder($parentOrder);
            $childOrder = $this->copyOrderProduct($parentOrder,$childOrder);

            $subOrder = (new SubOrder())
                ->setParentOrderId($parentOrder->getId())
                ->setSubOrderId($childOrder->getId())
                ->setToken(uniqid())
                ->setCreatedAt(new DateTime())
                ->setAuthorizedPaymentOption($data['authorizedPaymentOption'] ?? [])
                ->setAmountAlreadyPaid($data['amountAlreadyPaid'] ?? 0);
            $subOrder->save();

            return $subOrder;
        } catch (Exception $exception) {
            Tlog::getInstance()->addError(
                sprintf("Create subOrder error [%s] : %s",
                    $parentOrder->getId(),
                    $exception->getMessage())
            );
            throw $exception;
        }
    }

    private function createChildOrder(Order $parentOrder): Order
    {
        $childOrder = $parentOrder->copy();

        $childOrder->setId(null)->setRef(null)->setNew(true);
        $childOrder->resetModified(OrderTableMap::COL_CREATED_AT);
        $childOrder->resetModified(OrderTableMap::COL_UPDATED_AT);
        $childOrder->resetModified(OrderTableMap::COL_VERSION_CREATED_AT);
        $childOrder->setCustomerId($parentOrder->getCustomer()->getId());
        $childOrder->setCurrencyId($parentOrder->getCurrencyId());
        $childOrder->setCurrencyRate($parentOrder->getCurrencyRate());
        $childOrder->setLangId($parentOrder->getLangId());
        $childOrder->setDeliveryOrderAddressId($parentOrder->getDeliveryOrderAddressId());
        $childOrder->setInvoiceOrderAddressId($parentOrder->getInvoiceOrderAddressId());
        $childOrder->setStatusId(OrderStatusQuery::getNotPaidStatus()->getId());
        $childOrder->setDiscount($parentOrder->getDiscount());
        $childOrder->save();
        return $childOrder;
    }

    private function copyOrderProduct(Order $parentOrder, Order $childOrder): Order
    {
        foreach ($parentOrder->getOrderProducts() as $parentOrderProduct) {
            $newOrderProduct = $parentOrderProduct->copy();
            $newOrderProduct->setId(null)->setNew(true);
            $newOrderProduct->setOrderId($childOrder->getId());
            $newOrderProduct->resetModified(OrderProductTableMap::COL_CREATED_AT);
            $newOrderProduct->resetModified(OrderProductTableMap::COL_UPDATED_AT);
            $newOrderProduct->save();

            /** @var OrderProductTax $parentOrderProductTax */
            foreach ($parentOrderProduct->getOrderProductTaxes() as $parentOrderProductTax) {
                $newOrderProductTax = $parentOrderProductTax->copy();
                $newOrderProductTax->setOrderProductId($newOrderProduct->getId());
                $newOrderProductTax->resetModified(OrderProductTableMap::COL_CREATED_AT);
                $newOrderProductTax->resetModified(OrderProductTableMap::COL_UPDATED_AT);
                $newOrderProductTax->save();
            }

            /** @var OrderProductAttributeCombination $parentOrderProductAttributeCombination */
            foreach ($parentOrderProduct->getOrderProductAttributeCombinations() as $parentOrderProductAttributeCombination) {
                $newOrderProductAttributeCombination = $parentOrderProductAttributeCombination->copy();
                $newOrderProductAttributeCombination->resetModified(OrderProductAttributeCombinationTableMap::COL_CREATED_AT);
                $newOrderProductAttributeCombination->resetModified(OrderProductAttributeCombinationTableMap::COL_UPDATED_AT);
                $newOrderProductAttributeCombination->setOrderProductId($newOrderProduct->getId());
                $newOrderProductAttributeCombination->save();
            }
        }
        return $childOrder;
    }

    public function  isSubOrder(int $orderId):bool {
        return !SubOrderQuery::create()->findBySubOrderId($orderId)->isEmpty();
    }

    public function updateParentOrderStatus(int $childOrderId, string $statusCode): Order
    {
        $orderStatus = OrderStatusQuery::create()->findOneByCode($statusCode);

        while(null !== $subOrder = SubOrderQuery::create()->findOneBySubOrderId($childOrderId)) {
            $parentOrder = $subOrder->getOrderRelatedByParentOrderId();
            $parentOrder->setOrderStatus($orderStatus)
                ->save();
            $childOrderId = $parentOrder->getId();
        }

        return $parentOrder;
    }

    public function getHistoryPayment(SubOrder $subOrder)
    {
        $result = [];
        do{
            $result[] = $this->getPaymentAmount($subOrder);
        }while(null !== $subOrder = SubOrderQuery::create()->findOneBySubOrderId($subOrder->getParentOrderId()));
        return  $result;
    }

    private function getPaymentAmount(SubOrder $subOrder)
    {
        $parentOrder = $subOrder->getOrderRelatedByParentOrderId();
        return [
            'paymentCode'=>$parentOrder->getPaymentModuleInstance()->getCode(),
            'parentRef' => $parentOrder->getRef(),
            'amountAlreadyPaid' => $subOrder->getAmountAlreadyPaid()
        ];
    }

    public function fillCartFromSubOrderChild(Order $childOrder)
    {
        $cart = $this->requestStack->getSession()->getSessionCart($this->dispatcher);
        $orderProducts = $childOrder->getOrderProducts();

        foreach ($orderProducts as $orderProduct) {
            $newEvent = new CartEvent($cart);
            $newEvent->setQuantity($orderProduct->getQuantity());

            $pse = ProductSaleElementsQuery::create()
                ->filterById($orderProduct->getProductSaleElementsId())
                ->findOne();

            if (null === $pse) {
                $pse = ProductSaleElementsQuery::create()
                    ->filterByRef($orderProduct->getProductSaleElementsRef())
                    ->findOne();
            }

            if (null === $pse) {
                continue;
            }

            $newEvent->setProduct($pse->getProductId());
            $newEvent->setNewness(true);
            $newEvent->setAppend(false);
            $newEvent->setProductSaleElementsId($pse->getId());

            $this->dispatcher->dispatch($newEvent, TheliaEvents::CART_ADDITEM);
            $cartItem = $newEvent->getCartItem();
            $cartItem->setPrice($orderProduct->getPrice())
                ->setPromoPrice($orderProduct->getPromoPrice())
                ->setPromo($orderProduct->getWasInPromo());

            $cartItem->save();

            $session = $this->requestStack->getSession();
            $cartItemFromOrder = $session->get(self::CART_ITEM_FROM_ORDER_SESSION_KEY, []);
            $cartItemFromOrder[] = $cartItem->getId();
            $session->set(self::CART_ITEM_FROM_ORDER_SESSION_KEY, $cartItemFromOrder);
        }

    }
    public function getCustomerAddressOrCreate(
        int $addressId,
        int $customerId,
            $deliveryMode = null,

    ): Address
    {
        $orderAddress = OrderAddressQuery::create()->findPk($addressId);


        $address = AddressQuery::create()
            ->filterByCustomerId($customerId)
            ->filterByTitleId($orderAddress->getCustomerTitleId())
            ->filterByFirstname($orderAddress->getFirstname())
            ->filterByLastname($orderAddress->getLastname())
            ->filterByAddress1($orderAddress->getAddress1())
            ->filterByAddress2($orderAddress->getAddress2())
            ->filterByAddress3($orderAddress->getAddress3())
            ->filterByCity($orderAddress->getCity())
            ->filterByCountryId($orderAddress->getCountryId())
            ->filterByPhone($orderAddress->getPhone())
            ->filterByCellphone($orderAddress->getCellphone())
            ->filterByStateId($orderAddress->getStateId())
            ->limit(1)
            ->findOneOrCreate();


        if ($deliveryMode !== null && $deliveryMode !== 'delivery') {
            $address->setLabel(self::PICKUP_LABEL_ADDRESS)->save();
        }

        return $address;
    }

    public function getAmountAlreadyPaid(?SubOrder $suborder)
    {
        $historySubOrder = $this->getHistoryPayment($suborder);
        $totalAmount = 0;
        foreach ($historySubOrder as $history){
            $totalAmount +=$history['amountAlreadyPaid'];
        }
        return $totalAmount;
    }
}