<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Error\ProblemResponseFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private const string HEADER_NAME = 'X-API-Key';

    public function __construct(
        private readonly Connection $connection,
        private readonly ProblemResponseFactory $problemResponseFactory,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $apiKey = $request->headers->get(self::HEADER_NAME);

        if ($apiKey === null || $apiKey === '') {
            throw new CustomUserMessageAuthenticationException('Missing API key.');
        }

        $keyHashHex = hash('sha256', $apiKey);
        $name = $this->connection->fetchOne(
            'SELECT name FROM api_key WHERE key_hash = decode(:keyHashHex, \'hex\')',
            ['keyHashHex' => $keyHashHex],
        );

        if (!\is_string($name)) {
            throw new CustomUserMessageAuthenticationException('Unknown API key.');
        }

        $accountName = $name;

        return new SelfValidatingPassport(
            new UserBadge($accountName, static fn (): InMemoryUser => new InMemoryUser($accountName, null, ['ROLE_API_CLIENT'])),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->problemResponseFactory->unauthorized($request);
    }
}
