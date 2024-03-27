<?php

namespace SubOrderGenerator\Service;

use DateTime;
use Exception;
use SubOrderGenerator\Model\SubOrder;
use SubOrderGenerator\Model\SubOrderQuery;
use SubOrderGenerator\SubOrderGenerator;
use Thelia\Log\Tlog;

use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderProductAttributeCombinationTableMap;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductAttributeCombination;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderStatusQuery;

class SubOrderService
{
    /** @var MailerFactory */
    protected $mailer;

    public function __construct(
        MailerFactory $mailer
    )
    {
        $this->mailer = $mailer;
    }
    const PRODUCT_REF_ALREADY_PAID = "ALREADY_PAID";
    public function createSubOrderFromParent(array $data): SubOrder{

        try {
            $parentOrder = OrderQuery::create()->findOneById($data['parentOrderId']);
            $childOrder = $this->createChildOrder($parentOrder);
            $childOrder = $this->copyOrderProduct($parentOrder,$childOrder);

            if($data['amountAlreadyPaid']){
                $orderProduct = new OrderProduct();
                $orderProduct
                    ->setProductRef(self::PRODUCT_REF_ALREADY_PAID)
                    ->setVirtual(true)
                    ->setQuantity(1)
                    ->setTitle('Montant déjà payé pour la commande '.$parentOrder->getRef())
                    ->setPrice($data['amountAlreadyPaid'])
                    ->setOrderId($childOrder->getId())
                    ->setNew(true);
                $orderProduct->save();

                $newOrderProductTax = new OrderProductTax();
                $newOrderProductTax->setOrderProductId($orderProduct->getId());
                $newOrderProductTax->save();

            }
            $subOrder = (new SubOrder())
                ->setParentOrderId($parentOrder->getId())
                ->setSubOrderId($childOrder->getId())
                ->setToken(uniqid())
                ->setCreatedAt(new DateTime())
                ->setAuthorizedPaymentOption($data['authorizedPaymentOption'] ?? []);
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
        $orderStatusCancelled = OrderStatusQuery::create()->findOneByCode($statusCode);
        $parentOrder = SubOrderQuery::create()->findOneBySubOrderId($childOrderId)
            ->getOrderRelatedByParentOrderId();

        $parentOrder->setOrderStatus($orderStatusCancelled)
            ->save();

        return $parentOrder;
    }

    public function sendSubOrderLink(SubOrder $subOrder)
    {
        $parentOrder = $subOrder->getOrderRelatedByParentOrderId();
        $customer = $parentOrder->getCustomer();
        $email = $this->mailer->createEmailMessage(
            SubOrderGenerator::SUBORDER_LINK_MESSAGE_NAME,
            [ConfigQuery::getStoreEmail() => ConfigQuery::getStoreName()],
            [$customer->getEmail() => $customer->getFirstname().' '.$customer->getLastname()],
            //todo create link to front template.
            ['subOrderlink' => $subOrder]
        );
        $this->mailer->send($email);
    }
}