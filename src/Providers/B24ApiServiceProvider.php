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
use X3Group\B24Api\Http\Middleware\B24AuthApp;

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
         * Первичный вход на приложение, сохранение авторизации пользователя, авторизация его в рамках приложения
         * Хождение в рамках приложения с отключенной проверкой CsrfToken, если пользователь авторизован
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
         * Запросы из самого приложения, пользователь должен быть авторизован в момент запроса
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
         * Запрос данных из приложения, пользователь должен быть авторизован в момент запроса, memberId берется из сессии. Отключена проверка csrf
         */
        $router->middlewareGroup('b24appUserCall', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            B24AuthApp::class
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

        $router->group(['middleware' => 'b24appUserCall'], function () {
            if (file_exists(base_path('routes/b24appUserCall.php')))
                $this->loadRoutesFrom(base_path('routes/b24appUserCall.php'));
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
        });

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'b24api');

        $this->publishes([
            __DIR__ . '/../routes/b24app.php' => base_path('routes/b24app.php'),
            __DIR__ . '/../routes/b24appUser.php' => base_path('routes/b24appUser.php'),
            __DIR__ . '/../routes/b24appUserApiCall.php' => base_path('routes/b24appUserApiCall.php'),
            __DIR__ . '/../routes/b24appUserCall.php' => base_path('routes/b24appUserCall.php'),
            __DIR__ . '/../resources/views' => resource_path('views/b24api'),
        ],'routes');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
