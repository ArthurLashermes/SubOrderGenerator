<?php

namespace SubOrderGenerator\EventListeners;

use OpenApi\Events\ModelExtendDataEvent;
use OpenApi\Model\Api\CartItem;
use SubOrderGenerator\Model\SubOrder;
use SubOrderGenerator\Model\SubOrderQuery;
use SubOrderGenerator\Service\SubOrderService;
use SubOrderGenerator\SubOrderGenerator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\Order\OrderPaymentEvent;
use Thelia\Core\Event\Order\OrderPayTotalEvent;
use Thelia\Core\Event\Payment\IsValidPaymentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Event\CartEvent;
use Thelia\Model\Event\CartItemEvent;
use Thelia\Model\Order as OrderModel;

class SubOrderPayListener implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private SubOrderService $subOrderService
    )
    {
    }

    public function paySubOrder(OrderEvent $event, $eventName, EventDispatcherInterface $dispatcher)
    {
        $session = $this->requestStack->getCurrentRequest()->getSession();

        //if no suborder token found do nothing
        if(null === $subOrderToken = $session->get(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY)){
            return;
        }

        if(null === $suborderLink = SubOrderQuery::create()->findOneByToken($subOrderToken)){
            throw new NotFoundHttpException('No subOrder Associated to token');
        }
        $event->stopPropagation();
        $order = $event->getOrder();

        $suborder = $suborderLink->getOrderRelatedBySubOrderId();
        $suborder->setPaymentModuleId($order->getPaymentModuleId());
        $suborder->save();

        $session->remove(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY);
        $session->clearSessionCart($dispatcher);

        /* but memorize placed order */
        $event->setOrder(new OrderModel());
        $event->setPlacedOrder($suborder);

        /* call pay method */
        $payEvent = new OrderPaymentEvent($suborder);

        $dispatcher->dispatch($payEvent, TheliaEvents::MODULE_PAY);

        if ($payEvent->hasResponse()) {
            $event->setResponse($payEvent->getResponse());
        }
    }

    public function filterSubOrderPayment(IsValidPaymentEvent $event)
    {
        $session = $this->requestStack->getCurrentRequest()->getSession();

        //if no suborder token found do nothing
        if(null === $subOrderToken = $session->get(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY)){
            return;
        }

        if(null === $suborderLink = SubOrderQuery::create()->findOneByToken($subOrderToken)){
            throw new NotFoundHttpException('No subOrder Associated to token');
        }

        $availablePayments = $suborderLink->getAuthorizedPaymentOption();
        $event->setValidModule(in_array($event->getModule()->getCode(), $availablePayments));
    }

    public function changeTotalForSubOrder(OrderPayTotalEvent $event){
        $order = $event->getOrder();
        /** @var SubOrder $subOrder */
        $subOrder = SubOrderQuery::create()->findOneBySubOrderId($order->getId());
        if($subOrder){
            $event->setTotal($event->getTotal() - $this->subOrderService->getAmountAlreadyPaid($subOrder));
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_PAY => ['paySubOrder', 200],
            TheliaEvents::MODULE_PAYMENT_IS_VALID => ['filterSubOrderPayment'],
            TheliaEvents::ORDER_PAY_GET_TOTAL => ['changeTotalForSubOrder', 70],
        ];
    }
}
