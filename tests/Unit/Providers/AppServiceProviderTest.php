<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

test('forces the root URL and HTTPS scheme from config app.url in production', function () {
    config(['app.url' => 'https://tcgvault.cativo.dev']);
    $this->app->detectEnvironment(fn () => 'production');

    URL::shouldReceive('forceRootUrl')->once()->with('https://tcgvault.cativo.dev');
    URL::shouldReceive('forceScheme')->once()->with('https');

    (new AppServiceProvider($this->app))->boot();
});

test('does not force the root URL outside production', function () {
    config(['app.url' => 'https://tcgvault.cativo.dev']);
    $this->app->detectEnvironment(fn () => 'testing');

    URL::shouldReceive('forceRootUrl')->never();
    URL::shouldReceive('forceScheme')->never();

    (new AppServiceProvider($this->app))->boot();
});
