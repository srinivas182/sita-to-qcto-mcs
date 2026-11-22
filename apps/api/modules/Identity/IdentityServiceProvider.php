<?php

declare(strict_types=1);

namespace Modules\Identity;

use Illuminate\Auth\AuthManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Models\PersonalAccessToken;
use Modules\Identity\Otp\LogOtpChannel;
use Modules\Identity\Otp\OtpChannel;
use Modules\Identity\Otp\OtpDispatcher;
use Modules\Identity\Support\PlatformUserProvider;
use Modules\Support\ModuleServiceProvider;
use RuntimeException;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpDispatcher::class, function (Application $app): OtpDispatcher {
            /** @var list<string> $order */
            $order = config('identity.otp.channels', ['whatsapp', 'sms']);

            return new OtpDispatcher(array_map(fn (string $name): OtpChannel => $this->channel($app, trim($name)), $order));
        });
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->app->make(AuthManager::class)->provider('platform-eloquent', function (Application $app, array $config): PlatformUserProvider {
            return new PlatformUserProvider($app->make(Hasher::class), (string) $config['model']);
        });

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
        ]);

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(12)->letters()->numbers());
    }

    private function channel(Application $app, string $name): OtpChannel
    {
        $driver = config("identity.otp.drivers.{$name}", 'log');

        return match ($driver) {
            'log' => new LogOtpChannel($name),
            default => $app->bound("otp.channel.{$driver}")
                ? $app->make("otp.channel.{$driver}")
                : throw new RuntimeException("OTP driver [{$driver}] for channel [{$name}] is not installed."),
        };
    }
}
