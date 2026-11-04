<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Swagger dokümanında tüm uç noktalar Bearer token ister.
        Scramble::configure()
            ->routes(fn (Route $route) => str_starts_with($route->uri, 'api/') && ! $route->isFallback)
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        // Şifre denemesi: aynı e-posta + IP için dakikada 5 deneme
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip())
            ->response(fn () => response()->json(['message' => 'Çok fazla deneme. Lütfen bir dakika sonra tekrar deneyin.'], 429)));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('usage', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
