<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Account;
use App\Module\Identity\SessionRevoker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records session id → account after authentication. Runs on the response because Symfony migrates the session
 * id on login after the success handler; a session attribute avoids a write on every request.
 */
final class SessionIndexSubscriber implements EventSubscriberInterface
{
    private const string ATTRIBUTE = '_tabularius_indexed_session';

    public function __construct(
        private readonly Security $security,
        private readonly SessionRevoker $sessions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['index', -16]];
    }

    public function index(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $account = $this->security->getUser();
        if (!$event->isMainRequest() || !$account instanceof Account || !$request->hasSession() || !$request->getSession()->isStarted()) {
            return;
        }
        $session = $request->getSession();
        if ($session->get(self::ATTRIBUTE) === $session->getId()) {
            return;
        }
        $this->sessions->remember($session->getId(), $account);
        $session->set(self::ATTRIBUTE, $session->getId());
    }
}
