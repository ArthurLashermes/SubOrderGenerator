<?php

namespace SubOrderGenerator\EventListeners;

use SubOrderGenerator\SubOrderGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\ViewCheckEvent;

class GetOutOfTheSubOrderProcessListener implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack    $requestStack,
        private EventDispatcherInterface $eventDispatcher,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::VIEW_CHECK => ['onViewChange', 128],
        ];
    }

    public function onViewChange(ViewCheckEvent $viewCheckEvent): void
    {
        $session = $this->requestStack->getCurrentRequest()->getSession();
        if(!$session->get(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY)){
            return;
        }
        $nameView = $viewCheckEvent->getView();
        if ($nameView !== 'order-delivery'){
            $session->remove(SubOrderGenerator::SUBORDER_TOKEN_SESSION_KEY);
            $session->clearSessionCart($this->eventDispatcher);
        }
    }
}