<?php

namespace App\Providers;

use App\Contracts\ActorResolver;
use App\Services\SessionActorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single seam for identity. When the SSO application is ready, bind an
        // OIDC-backed implementation here and nothing else has to change
        // (docs/auth-seam.md).
        $this->app->singleton(ActorResolver::class, fn () => new SessionActorResolver);
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');
        Paginator::defaultSimpleView('partials.pagination');

        // Compact pagination URLs: /board?page=2 instead of ?page=2&filters...
        Paginator::useBootstrapThree();

        Str::macro('initials', function (string $name): string {
            return mb_substr(trim($name), 0, 1);
        });

        View::composer('*', function ($view) {
            if (! array_key_exists('basePath', $view->getData())) {
                $view->with('basePath', rtrim((string) config('workreport.base_path'), '/'));
            }
        });

        Date::use(CarbonImmutable::class);
    }
}
