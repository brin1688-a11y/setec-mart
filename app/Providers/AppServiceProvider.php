<?php

namespace App\Providers;

use App\Services\Chat\ChatClient;
use App\Services\CutLuy\CutLuyClient;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `artisan serve` hands its child process an allowlist of environment
        // variables and blanks everything else, so a TRUSTED_PROXIES set in
        // .env never reaches the server actually answering requests — links
        // and images then come out on 127.0.0.1 even behind a tunnel.
        ServeCommand::$passthroughVariables[] = 'TRUSTED_PROXIES';

        $this->app->singleton(ChatClient::class, function () {
            return new ChatClient(
                apiKey: config('services.gemini.key'),
                model: config('services.gemini.model'),
                baseUrl: config('services.gemini.base_url'),
                timeout: (int) config('services.gemini.timeout'),
                maxTokens: (int) config('services.gemini.max_tokens'),
            );
        });

        $this->app->singleton(CutLuyClient::class, function () {
            return new CutLuyClient(
                baseUrl: config('services.cutluy.base_url'),
                apiKey: config('services.cutluy.key'),
                timeout: (int) config('services.cutluy.timeout', 15),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The site is styled with Bootstrap, not Tailwind. Laravel's
        // default paginator markup is Tailwind-only, so its chevron SVGs
        // come out unstyled and render full-page size.
        Paginator::useBootstrapFive();

        // The shop logo in its two shapes, resolved once here rather than
        // testing for files in Blade. When neither exists the views fall back
        // to the leaf mark, so no broken image or 404 reaches the browser.
        //
        //   brandMark  the emblem alone, square — for the 38px header tile,
        //              the footer and the admin rail, where the full lockup's
        //              lettering would be an illegible smudge.
        //   brandLogo  the whole lockup, for the places that give it room.
        $pick = fn (array $files) => collect($files)
            ->first(fn ($file) => file_exists(public_path($file))) ?: null;

        // Guests see the buy buttons (they lead to sign-in); staff do not,
        // because the shopping routes turn them away anyway.
        Blade::if('shopper', fn () => ! (auth()->user()?->isAdmin() ?? false));

        View::share('brandMark', $pick(['images/logo-mark.svg', 'images/logo-mark.png', 'images/logo.png']));
        View::share('brandLogo', $pick(['images/logo-full.png', 'images/logo.svg', 'images/logo.png']));
    }
}
