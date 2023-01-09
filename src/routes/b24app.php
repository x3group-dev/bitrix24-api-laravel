<?php

use Illuminate\Support\Facades\Route;

/**
 * Роуты для приложений типа: использует только API
 */

Route::match(['post'],'/install','\X3Group\B24Api\Http\Controllers\B24InstallController@install');
