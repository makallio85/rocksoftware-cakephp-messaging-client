<?php
declare(strict_types=1);

namespace TestApp\Middleware;

use ArrayObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for the application's authentication: the session's `Auth` is
 * the `identity` request attribute, as the Authentication plugin leaves it.
 */
class SessionIdentityMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var \Cake\Http\ServerRequest $request */
        $auth = $request->getSession()->read('Auth');
        if (is_array($auth)) {
            $request = $request->withAttribute('identity', new ArrayObject($auth));
        }

        return $handler->handle($request);
    }
}
