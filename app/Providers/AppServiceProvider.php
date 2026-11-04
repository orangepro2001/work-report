<?php

namespace App\Providers;

use App\Contracts\ActorResolver;
use App\Services\SessionActorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
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

        Str::macro('initials', function (string $name): string {
            return mb_substr(trim($name), 0, 1);
        });

        View::composer('*', function ($view) {
            if (! array_key_exists('basePath', $view->getData())) {
                $view->with('basePath', rtrim((string) config('workreport.base_path'), '/'));
            }
        });

        Date::use(CarbonImmutable::class);

        // A connection abandoned mid-transaction keeps its table locks until the
        // backend dies, which wedges the next run — this actually happened while
        // developing: an interrupted test suite left `drop table` waiting forever.
        // PostgreSQL can evict such a session itself; it costs one statement per
        // connection.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $name = $event->connection->getName();
            $timeout = config("database.connections.{$name}.idle_in_transaction_session_timeout");

            // SET takes no bind parameters, so the value is whitelisted first: only a plain integer with a
            // unit (or 0) is accepted, then it is quoted as a string literal.
            if ($timeout && $event->connection->getDriverName() === 'pgsql'
                && preg_match('/^(0|[0-9]{1,6}(ms|s|min))$/', (string) $timeout) === 1) {
                $event->connection->statement("SET idle_in_transaction_session_timeout = '{$timeout}'");
            }
        });
    }
}
