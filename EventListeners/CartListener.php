<?php

namespace SubOrderGenerator\EventListeners;

use OpenApi\Events\ModelExtendDataEvent;
use SubOrderGenerator\Model\SubOrderQuery;
use SubOrderGenerator\Service\SubOrderService;
use SubOrderGenerator\SubOrderGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Thelia\Core\Event\Cart\CartCreateEvent;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;

class CartListener implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private SubOrderService $subOrderService
    )
    {
    }

    public function clearSubOrderSession(){
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $session->remove(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY);
    }

    public function updateCart(ModelExtendDataEvent $event){

        $session = $this->requestStack->getCurrentRequest()->getSession();
        //if no suborder token found do nothing
        if(null === $subOrderToken = $session->get(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY)){
            return;
        }

        if(null === $suborderLink = SubOrderQuery::create()->findOneByToken($subOrderToken)){
            throw new NotFoundHttpException('No subOrder Associated to token');
        }
        $order = $suborderLink->getOrderRelatedBySubOrderId();

        $event->setExtendDataKeyValue("total_amount_sub_order", round($order->getTotalAmount() - $this->subOrderService->getAmountAlreadyPaid($suborderLink),2) );
    }


    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::CART_ADDITEM => ['clearSubOrderSession', 128],
            TheliaEvents::CART_DELETEITEM => ['clearSubOrderSession', 128],
            TheliaEvents::CART_UPDATEITEM => ['clearSubOrderSession', 128],
            TheliaEvents::CART_CLEAR => ['clearSubOrderSession', 128],
            TheliaEvents::CART_CREATE_NEW =>['clearSubOrderSession', 128],
            ModelExtendDataEvent::ADD_EXTEND_DATA_PREFIX . "cart" => ['updateCart', 10],
        ];
    }
}
