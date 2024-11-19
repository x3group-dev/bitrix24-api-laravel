<?php

use App\Http\Controllers\Bitrix24\AppController;
use Illuminate\Support\Facades\Route;

/**
 * Роуты для хождения в рамках приложения с отключенной проверкой CsrfToken.
 * Если пользователь не авторизован, будет создан пользователь и авторизован.
 *
 * Для приложений с интерфейсом.
 */

Route::post('/app', [AppController::class, 'index']);
