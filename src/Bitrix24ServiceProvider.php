<?php

namespace X3Group\Bitrix24;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TestController;
use Bitrix24\SDK\Core\ApiClient;
use Bitrix24\SDK\Core\ApiLevelErrorHandler;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Endpoints;
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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\RouteBinding;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Install\AppSetupRunner;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\UserAuthDatabaseStorage;
use X3Group\Bitrix24\Http\Middleware\B24AppMiddleware;
use X3Group\Bitrix24\Http\Middleware\B24AppUserMiddleware;
use X3Group\Bitrix24\Http\Middleware\B24AuthUserMiddleware;
use X3Group\Bitrix24\Listeners\PortalDomainUrlChangedListener;
use X3Group\Bitrix24\Logging\ContentTruncatingProcessor;
use X3Group\Bitrix24\Logging\MetadataProcessor;
use X3Group\Bitrix24\Logging\PersonalDataProcessor;
use X3Group\Bitrix24\Logging\SecretMaskingProcessor;
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

        // Структурированное логирование включается только при явном флаге;
        // по умолчанию (enabled = false) поведение пакета не меняется.
        if (config('structured-logging.enabled')) {
            $this->registerStructuredLoggingChannel();
            $this->routeApplicationErrorsToStructured();
        }
    }

    /**
     * Регистрирует лог-канал `structured`: ротируемый JSON-файл + процессоры
     * маскирования секретов/ПД, обрезки и меток.
     *
     * Канал собирается вручную (тем же стилем, что и bind 'b24log' выше):
     * new Logger + pushHandler(RotatingFileHandler) — это даёт детерминированный
     * контроль над порядком процессоров, независимо от версии Laravel-парсера
     * monolog-конфига. `Log::extend('structured')` делает его доступным через
     * app('log')->channel('structured').
     *
     * @return void
     */
    protected function registerStructuredLoggingChannel(): void
    {
        config(['logging.channels.structured' => ['driver' => 'structured']]);

        Log::extend('structured', function () {
            $handler = new RotatingFileHandler(
                filename: config('structured-logging.path'),
                maxFiles: config('structured-logging.max_files'),
            );
            $handler->setFormatter(new JsonFormatter());

            $logger = new Logger('structured');
            $logger->pushHandler($handler);

            // monolog применяет процессоры в порядке, обратном pushProcessor (LIFO),
            // поэтому пушим их в обратном порядке, чтобы фактический порядок был:
            // Secret → PersonalData → ContentTruncating → Metadata.
            $logger->pushProcessor(new MetadataProcessor(
                (string) config('structured-logging.schema_version'),
                (string) config('structured-logging.app'),
                (string) config('app.env'),
                fn () => auth()->user()?->member_id ?? null,
            ));
            $logger->pushProcessor(new ContentTruncatingProcessor(
                (int) config('structured-logging.truncate_at'),
            ));
            $logger->pushProcessor(new PersonalDataProcessor(
                config('structured-logging.personal_data_methods'),
                config('structured-logging.personal_data_keys'),
            ));
            $logger->pushProcessor(new SecretMaskingProcessor(
                config('structured-logging.secret_keys'),
            ));

            return $logger;
        });
    }

    /**
     * Дублирует записи приложения уровня >= WARNING (включая необработанные
     * исключения) в канал `structured`.
     *
     * Защита от самозацикливания: при повторной записи в канал structured мы
     * ставим в контекст флаг `__structured`; MessageLogged от этой записи ловится
     * снова, но флаг заставляет слушатель выйти сразу. Записи самого LoggingCore
     * пишутся в канал structured на уровне info (ниже WARNING), поэтому в ветку
     * дублирования вообще не попадают и вторую запись не порождают.
     *
     * @return void
     */
    protected function routeApplicationErrorsToStructured(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (($event->context['__structured'] ?? false) === true) {
                return;
            }

            if (!in_array($event->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }

            app('log')->channel('structured')->log(
                $event->level,
                $event->message,
                $event->context + ['__structured' => true],
            );
        });
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bitrix24.php', 'bitrix24');
        $this->mergeConfigFrom(__DIR__.'/../config/structured-logging.php', 'structured-logging');

        $this->app->bind(AppSetupRunner::class, fn($app) => new AppSetupRunner($app->make('log')));
        $this->app->bind(AppTokenWriter::class, fn($app) => new AppTokenWriter($app->make('log')));

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
                oauthServerUrl: OauthServerUrlResolver::orDefault($b24api->oauth_server_url),
            );

            // User
            $userClient = ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
                placementRequest: Request::createFromGlobals(),
                applicationProfile: $applicationProfile,
                eventDispatcher: new EventDispatcherAdapter(),
                oauthServerUrl: OauthServerUrlResolver::fromServerEndpoint($request->input('SERVER_ENDPOINT')),
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
                    endpoints: new Endpoints(
                        "https://{$parameters['domain']}",
                        OauthServerUrlResolver::orDefault(
                            B24App::query()->where('member_id', $parameters['memberId'])->value('oauth_server_url')
                        ),
                    ),
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

        $this->app->booted(function () {
            Schedule::call(function () {
                Bitrix24App::renewTokens();
                Bitrix24User::renewTokens();
            })->everyMinute();
        });
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
            __DIR__.'/../config/structured-logging.php' => config_path('structured-logging.php'),
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
        $this->commands([
            \X3Group\Bitrix24\Console\Commands\RemoveUninstalledPortals::class,
        ]);
    }
}
