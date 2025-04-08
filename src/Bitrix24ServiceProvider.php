<?php

namespace X3Group\Bitrix24;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TestController;
use Bitrix24\SDK\Core\ApiClient;
use Bitrix24\SDK\Core\ApiLevelErrorHandler;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Events\PortalDomainUrlChangedEvent;
use Bitrix24\SDK\Infrastructure\HttpClient\RequestId\DefaultRequestIdGenerator;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\RouteBinding;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\UserAuthDatabaseStorage;
use X3Group\Bitrix24\Http\Middleware\B24AppMiddleware;
use X3Group\Bitrix24\Http\Middleware\B24AppUserMiddleware;
use X3Group\Bitrix24\Http\Middleware\B24AuthUserMiddleware;
use X3Group\Bitrix24\Listeners\PortalDomainUrlChangedListener;
use X3Group\Bitrix24\Models\B24App;

class Bitrix24ServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot(Kernel $kernel): void
    {
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'x3group');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'x3group');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }

        $application = $kernel->getApplication();
        $router = $application->make(Router::class);

        /**
         * Защита для приложений типа: использует только API
         */
        $router->middlewareGroup('b24app', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
            B24AppMiddleware::class,
        ]);

        /**
         * Первичный вход на приложение, сохранение авторизации пользователя (laravel),
         * авторизация его в рамках приложения и laravel
         *
         * При включенных ThirdParty cookie авторизация б24 берется из сессии
         * Хождение в рамках приложения с отключенной проверкой CsrfToken
         *
         * Для приложений с интерфейсом
         */
        $router->middlewareGroup('b24appUser', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
            B24AppUserMiddleware::class,
        ]);

        /**
         * Запросы из фронта приложения с передачей авторизации через header X-b24api-access-token X-b24api-domain X-b24api-member-id
         * авторизует пользователя и делает запрос от него
         */
        $router->middlewareGroup('b24appFrontRequest', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
            B24AuthUserMiddleware::class,
        ]);

        $router->group(['middleware' => 'b24app'], function () {
            if (file_exists(base_path('routes/b24app.php')))
                $this->loadRoutesFrom(base_path('routes/b24app.php'));
        });

        $router->group(['middleware' => 'b24appUser'], function () {
            if (file_exists(base_path('routes/b24appUser.php')))
                $this->loadRoutesFrom(base_path('routes/b24appUser.php'));
        });

        $router->group(['middleware' => 'b24appFrontRequest'], function () {
            if (file_exists(base_path('routes/b24appFrontRequest.php')))
                $this->loadRoutesFrom(base_path('routes/b24appFrontRequest.php'));
        });

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->publishes([
            __DIR__ . '/../routes/b24app.php' => base_path('routes/b24app.php'),
            __DIR__ . '/../routes/b24appUser.php' => base_path('routes/b24appUser.php'),
            __DIR__ . '/../routes/b24appFrontRequest.php' => base_path('routes/b24appFrontRequest.php'),

            __DIR__ . '/../resources/views' => resource_path('views/b24api'),

            __DIR__ . '/Http/Controllers/Bitrix24/AppController.stub' => base_path('app/Http/Controllers/Bitrix24/AppController.php'),
            __DIR__ . '/Http/Controllers/Bitrix24/InstallController.stub' => base_path('app/Http/Controllers/Bitrix24/InstallController.php'),

            __DIR__ . '/Http/Controllers/Bitrix24/Events/OnApplicationInstallController.stub' => base_path('app/Http/Controllers/Bitrix24/Events/OnApplicationInstallController.php'),
            __DIR__ . '/Http/Controllers/Bitrix24/Events/OnApplicationUninstallController.stub' => base_path('app/Http/Controllers/Bitrix24/Events/OnApplicationUninstallController.php'),
        ], 'bitrix24-routes');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bitrix24.php', 'bitrix24');

        $this->app->bind('appEvents', function () {
            $eventDispatcher = new EventDispatcherAdapter();
            $eventDispatcher->listen(AuthTokenRenewedEvent::class, function (AuthTokenRenewedEvent $event) {
                /** @var AppAuthDatabaseStorage $appAuthStorage */
                $appAuthStorage = resolve(AppAuthDatabaseStorage::class, [
                    'memberId' => $event->getRenewedToken()->memberId,
                ]);
                $appAuthStorage->saveRenewedToken($event->getRenewedToken());
            });
            //$eventDispatcher->listen(PortalDomainUrlChangedEvent::class, function (PortalDomainUrlChangedEvent $event) {
            //    \logger('change url event');
            //    $listener = new PortalDomainUrlChangedListener();
            //    $listener->handle($event);
            //});

            return $eventDispatcher;
        });

        $this->app->bind('userEvents', function (Application $app, array $parameters) {
            $eventDispatcher = new EventDispatcherAdapter();

            if (isset($parameters['memberId']) && isset($parameters['userId'])) {
                $eventDispatcher->listen(
                    events: AuthTokenRenewedEvent::class,
                    listener: function (AuthTokenRenewedEvent $event) use ($parameters) {

                        resolve(UserAuthDatabaseStorage::class, [
                            'memberId' => $event->getRenewedToken()->memberId,
                            'userId' => $parameters['userId'],
                        ])->saveRenewedToken($event->getRenewedToken());
                    });
            }

            return $eventDispatcher;
        });

        $this->app->bind('b24log', function (Application $app, array $parameters) {
            $memberId = $parameters['memberId'];
            $domain = $parameters['domain'] ?? 'unknown';

            /** @var B24App $b24app */
            $b24app = B24App::query()
                ->where('member_id', $memberId)
                ->first();

            if ($b24app) {
                $domain = $b24app->domain;
            }

            $logger = new Logger('b24log');
            $logger->pushHandler(new RotatingFileHandler(
                filename: storage_path('logs/b24api/' . $domain . '-' . $memberId . '/b24api.log'),
                maxFiles: config('bitrix24.log_max_files'),
            ));

            return $logger;
        });

        $this->app->bind('bitrix24user', function (Application $app, array $parameters) {
            return new Bitrix24User($parameters['memberId'], $parameters['userId']);
        });

        $this->app->bind('bitrix24app', function (Application $app, array $parameters) {
            return new Bitrix24App($parameters['memberId']);
        });

        $this->app->bind(AppAuthDatabaseStorage::class, function (Application $app, array $parameters) {
            return new AppAuthDatabaseStorage($parameters['memberId']);
        });

        $this->app->bind(ApplicationProfile::class, function () {
            return new ApplicationProfile(
                clientId: config('bitrix24.client_id'),
                clientSecret: config('bitrix24.client_secret'),
                scope: Scope::initFromString(config('bitrix24.scope'))
            );
        });

        $this->app->bind(Bitrix24ApiClient::class, function () {
            //
            $applicationProfile = new ApplicationProfile(
                clientId: config('bitrix24.client_id'),
                clientSecret: config('bitrix24.client_secret'),
                scope: Scope::initFromString(config('bitrix24.scope'))
            );

            $memberId = null;

            $request = Request::createFromGlobals();

            if ($request->has('auth') && !empty($request->input('auth')['member_id'])) {
                $memberId = $request->input('auth')['member_id'];
            } elseif ($request->has('member_id') && !empty($request->input('member_id'))) {
                $memberId = $request->input('member_id');
            }

            if (is_null($memberId)) {
                throw new \Exception('Request has no member_id');
            }

            $b24api = B24App::query()
                ->where('member_id', $memberId)
                ->first();

            $authToken = new AuthToken(
                accessToken: $b24api->access_token,
                refreshToken: $b24api->refresh_token,
                expires: $b24api->expires,
                expiresIn: $b24api->expires_in,
            );

            $app = new ServiceBuilderFactory(
                eventDispatcher: resolve('appEvents'),
                log: resolve('b24log', [
                    'memberId' => $memberId
                ]),
            );

            $appClient = $app->init(
                applicationProfile: $applicationProfile,
                authToken: $authToken,
                bitrix24DomainUrl: "https://{$b24api->domain}",
            );

            // User
            $userClient = ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
                placementRequest: Request::createFromGlobals(),
                applicationProfile: $applicationProfile,
                eventDispatcher: new EventDispatcherAdapter(),
            );

            return new Bitrix24ApiClient(
                app: $appClient,
                user: $userClient,
            );
        });

        $this->app->bind(ApiClient::class, function (Application $app, array $parameters) {
            return new ApiClient(
                credentials: new Credentials(
                    webhookUrl: null,
                    authToken: new AuthToken(
                        accessToken: $parameters['accessToken'],
                        refreshToken: $parameters['refreshToken'],
                        expires: $parameters['expires'],
                        expiresIn: $parameters['expiresIn'],
                    ),
                    applicationProfile: new ApplicationProfile(
                        clientId: config('bitrix24.client_id'),
                        clientSecret: config('bitrix24.client_secret'),
                        scope: Scope::initFromString(config('bitrix24.scope'))
                    ),
                    domainUrl: "https://{$parameters['domain']}",
                ),
                client: HttpClient::create(),
                requestIdGenerator: new DefaultRequestIdGenerator(),
                apiLevelErrorHandler: new ApiLevelErrorHandler(resolve('b24log', [
                    'memberId' => $parameters['memberId']
                ])),
                logger: resolve('b24log', [
                    'memberId' => $parameters['memberId']
                ]),
            );
        });

        Event::listen(PortalDomainUrlChangedEvent::class, PortalDomainUrlChangedListener::class);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return ['bitrix24'];
    }

    /**
     * Console-specific booting.
     *
     * @return void
     */
    protected function bootForConsole(): void
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__.'/../config/bitrix24.php' => config_path('bitrix24.php'),
        ], 'bitrix24.config');

        // Publishing the views.
        /*$this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/x3group'),
        ], 'bitrix24.views');*/

        // Publishing assets.
        /*$this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/x3group'),
        ], 'bitrix24.assets');*/

        // Publishing the translation files.
        /*$this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/x3group'),
        ], 'bitrix24.lang');*/

        // Registering package commands.
        // $this->commands([]);
    }
}
