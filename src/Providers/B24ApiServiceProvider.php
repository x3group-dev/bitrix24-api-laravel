<?php

namespace X3Group\B24Api\Providers;

use X3Group\B24Api\B24Api;
use X3Group\B24Api\B24ApiUser;
use X3Group\B24Api\Http\Middleware\B24App;
use X3Group\B24Api\Http\Middleware\B24AppUser;
use X3Group\B24Api\Http\Middleware\B24AuthApi;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use X3Group\B24Api\Http\Middleware\B24AuthUser;

class B24ApiServiceProvider extends ServiceProvider
{
    public function boot(Kernel $kernel)
    {
        $application = $kernel->getApplication();
        $router = $kernel->getApplication()->make(Router::class);

        /**
         * Защита для приложений типа: использует только API
         */
        $router->middlewareGroup('b24app', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            B24App::class
        ]);

        /**
         * Первичный вход на приложение, сохранение авторизации пользователя (laravel), авторизация его в рамках приложения и laravel
         * При включенных ThirdParty cookie авторизация б24 берется из сессии
         * Хождение в рамках приложения с отключенной проверкой CsrfToken
         * Для приложений с интерфейсом
         */
        $router->middlewareGroup('b24appUser', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            B24AppUser::class
        ]);

        /**
         * @deprecated
         * подлежит удалению
         * не работает при запрещенных ThirdParty cookie
         * Запросы из фронта приложения, пользователь должен быть авторизован laravel в момент запроса
         */

        $router->middlewareGroup('b24appUserApiCall', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            B24AuthApi::class
        ]);

        /**
         * Запросы из фронта приложения с передачей авторизации через header X-b24api-access-token X-b24api-domain X-b24api-member-id
         * авторизует пользователя и делает запрос от него
         */
        $router->middlewareGroup('b24appFrontRequest', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
//            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            B24AuthUser::class
        ]);

        $router->group(['middleware' => 'b24app'], function () {
            if (file_exists(base_path('routes/b24app.php')))
                $this->loadRoutesFrom(base_path('routes/b24app.php'));
        });

        $router->group(['middleware' => 'b24appUser'], function () {
            if (file_exists(base_path('routes/b24appUser.php')))
                $this->loadRoutesFrom(base_path('routes/b24appUser.php'));
        });

        $router->group(['middleware' => 'b24appUserApiCall'], function () {
            if (file_exists(base_path('routes/b24appUserApiCall.php')))
                $this->loadRoutesFrom(base_path('routes/b24appUserApiCall.php'));
        });

        $router->group(['middleware' => 'b24appFrontRequest'], function () {
            if (file_exists(base_path('routes/b24appFrontRequest.php')))
                $this->loadRoutesFrom(base_path('routes/b24appFrontRequest.php'));
        });

        $application->make('config')->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'b24user',
        ]);

        $application->make('config')->set('auth.providers.b24user', [
            'driver' => 'eloquent',
            'model' => \X3Group\B24Api\Models\B24User::class,
        ]);

        $application->make('config')->set('app.timezone', 'Europe/Moscow');
        $application->make('config')->set('session.secure', 'true');
        $application->make('config')->set('session.http_only', 'true');
        $application->make('config')->set('session.same_site', 'none');

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->call(function () {
                B24Api::renewTokens();
                B24ApiUser::renewTokens();
            })->everyMinute();

            $schedule->call(function () {
                B24Api::checkStatus();
            })->hourly();
        });

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'b24api');

        $this->publishes([
            __DIR__ . '/../routes/b24app.php' => base_path('routes/b24app.php'),
            __DIR__ . '/../routes/b24appUser.php' => base_path('routes/b24appUser.php'),
//            __DIR__ . '/../routes/b24appUserApiCall.php' => base_path('routes/b24appUserApiCall.php'),
            __DIR__ . '/../routes/b24appFrontRequest.php' => base_path('routes/b24appFrontRequest.php'),
            __DIR__ . '/../resources/views' => resource_path('views/b24api'),
        ],'routes');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
