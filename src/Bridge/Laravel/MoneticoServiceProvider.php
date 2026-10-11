<?php

declare(strict_types=1);

namespace Piston\Monetico\Bridge\Laravel;

use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\NotificationVerifier;
use Piston\Monetico\Security\SealVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Wires Monetico into a Laravel application.
 *
 * One singleton, built from config, plus the notification verifier on its own
 * for the common case of a controller that only needs to check a seal.
 *
 * The PSR-18 client is resolved from the container *if* something has bound it
 * and left to discovery otherwise, which is what makes `composer require` the
 * only step for a shop that already has Guzzle - and what keeps the
 * configuration from naming a transport the application may not have.
 */
final class MoneticoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/monetico.php', 'monetico');

        $this->app->singleton(Monetico::class, function (Container $app): Monetico {
            $repository = $app->make(Repository::class);
            $config = $repository->get('monetico', []);

            /** @var array<string, mixed> $config */
            $config = is_array($config) ? $config : [];

            return new Monetico(
                credentials: self::credentials($config),
                environment: self::environment($config),
                http: self::optional($app, ClientInterface::class),
                requests: self::optional($app, RequestFactoryInterface::class),
                streams: self::optional($app, StreamFactoryInterface::class),
                seals: ($config['accept_legacy_seals'] ?? true) ? new SealVerifier : SealVerifier::strict(),
                timezone: self::timezone($config),
            );
        });

        $this->app->singleton(
            NotificationVerifier::class,
            static fn (Container $app): NotificationVerifier => $app->make(Monetico::class)->verifier(),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/monetico.php' => $this->app->configPath('monetico.php'),
            ], 'monetico-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Monetico::class, NotificationVerifier::class];
    }

    /** @param array<string, mixed> $config */
    private static function credentials(array $config): Credentials
    {
        foreach (['terminal', 'key', 'company'] as $required) {
            if (! is_string($config[$required] ?? null) || $config[$required] === '') {
                // Said here rather than letting a value object complain about
                // an empty string: the fix is in .env, and the message should
                // say so.
                throw new RuntimeException(sprintf(
                    'Monetico is not configured: MONETICO_%s is missing. Publish the config with '
                    .'`php artisan vendor:publish --tag=monetico-config` and set the three values Monetico issued.',
                    strtoupper($required),
                ));
            }
        }

        /** @var array{terminal: string, key: string, company: string} $config */
        return Credentials::fromStrings($config['terminal'], $config['key'], $config['company']);
    }

    /** @param array<string, mixed> $config */
    private static function environment(array $config): Environment
    {
        $value = $config['environment'] ?? Environment::Sandbox->value;

        if (! is_string($value) || Environment::tryFrom($value) === null) {
            throw new RuntimeException(sprintf(
                'monetico.environment must be "sandbox" or "production", got "%s".',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return Environment::from($value);
    }

    /** @param array<string, mixed> $config */
    private static function timezone(array $config): ?DateTimeZone
    {
        $value = $config['timezone'] ?? null;

        return is_string($value) && $value !== '' ? new DateTimeZone($value) : null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    private static function optional(Container $app, string $abstract): ?object
    {
        return $app->bound($abstract) ? $app->make($abstract) : null;
    }
}
