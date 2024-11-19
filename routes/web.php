<?php

use Illuminate\Support\Facades\Route;

Route::match(['head'],'/app/', function () {
    return '';
});
Route::match(['head'],'/install/', function () {
    return '';
});
