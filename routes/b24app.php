<?php

use App\Http\Controllers\Bitrix24\InstallController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Bitrix24\Events\OnApplicationInstallController;
use App\Http\Controllers\Bitrix24\Events\DemoOnApplicationUninstallController;

/**
 * Роуты для приложений типа: использует только API.
 */

Route::post('/install', [InstallController::class, 'install']);

Route::prefix('/events')->group(function () {
    Route::post('/onApplicationInstall', [OnApplicationInstallController::class, 'handle']);
    Route::post('/onApplicationUninstall', [DemoOnApplicationUninstallController::class, 'handle']);
});
