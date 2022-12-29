<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <script src="//api.bitrix24.com/api/v1/"></script>
    <title>Приложение</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
<div id="app">
    app
</div>
</body>
</html>
