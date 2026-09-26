<?php

declare(strict_types=1);

namespace App\Api\Error;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ProblemDetailsExceptionListener implements EventSubscriberInterface
{
    public function __construct(private ProblemResponseFactory $factory)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $request = $event->getRequest();

        $response = match (true) {
            $throwable instanceof InvalidParameterException => $this->factory->invalidParameter($request, $throwable),
            $throwable instanceof NotFoundException => $this->factory->notFound($request, $throwable->detail),
            $throwable instanceof MethodNotAllowedHttpException => $this->factory->methodNotAllowed($request, $throwable),
            $throwable instanceof NotFoundHttpException => $this->factory->notFound($request),
            default => $this->factory->internalError($request, $throwable),
        };

        $event->setResponse($response);
        $event->stopPropagation();
    }
}
