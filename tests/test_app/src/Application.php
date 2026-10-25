<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use RockSoftware\MessagingClient\MessagingClientPlugin;
use TestApp\Middleware\SessionIdentityMiddleware;

/**
 * A consuming application: the queue an application template ships, a
 * stand-in for its authentication, and the plugin.
 */
class Application extends BaseApplication
{
    /**
     * @return void
     */
    public function bootstrap(): void
    {
        $this->addPlugin(MessagingClientPlugin::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware())
            ->add(new SessionIdentityMiddleware());
    }

    /**
     * The application has no routes of its own; the plugin's are added by its hook.
     *
     * @param \Cake\Routing\RouteBuilder $routes The builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
    }
}
