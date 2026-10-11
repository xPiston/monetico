<?php

declare(strict_types=1);

namespace Piston\Monetico\Bridge\Symfony;

use DateTimeZone;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\NotificationVerifier;
use Piston\Monetico\Security\SealVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Wires Monetico into a Symfony application.
 *
 * ```yaml
 * # config/packages/monetico.yaml
 * monetico:
 *     terminal: '%env(MONETICO_TERMINAL)%'
 *     key: '%env(MONETICO_KEY)%'
 *     company: '%env(MONETICO_COMPANY)%'
 *     environment: '%env(MONETICO_ENVIRONMENT)%'
 * ```
 *
 * `Monetico` and `NotificationVerifier` are then autowired into controllers
 * and services like anything else. The PSR-18 client is taken from the
 * container when one is registered - which it is as soon as
 * symfony/http-client is installed - and `nullOnInvalid()` means its absence
 * is not a container error but a clear exception the day somebody calls
 * `refund()`.
 */
final class MoneticoBundle extends AbstractBundle
{
    protected string $extensionAlias = 'monetico';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('terminal')->isRequired()->cannotBeEmpty()
            ->info('The 7-character virtual point-of-sale number (TPE).')->end()
            ->scalarNode('key')->isRequired()->cannotBeEmpty()
            ->info('The 40-hexadecimal-character merchant security key.')->end()
            ->scalarNode('company')->isRequired()->cannotBeEmpty()
            ->info('The company code (societe) issued with the contract.')->end()
            ->enumNode('environment')->values(['sandbox', 'production'])->defaultValue('sandbox')
            ->info('Never derived from the kernel environment: a staging server pointed at production takes real money.')->end()
            ->booleanNode('accept_legacy_seals')->defaultTrue()
            ->info('Also accept the pre-migration seal, which instalments created before it still arrive with.')->end()
            ->scalarNode('timezone')->defaultValue('Europe/Paris')
            ->info('The zone the dates sent to the platform are formatted in.')->end()
            ->end();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{terminal: string, key: string, company: string, environment: string, accept_legacy_seals: bool, timezone: string} $config */
        $services = $container->services();

        $services->set(Credentials::class)
            ->factory([Credentials::class, 'fromStrings'])
            ->args([$config['terminal'], $config['key'], $config['company']]);

        $seals = $services->set(SealVerifier::class);

        if (! $config['accept_legacy_seals']) {
            $seals->factory([SealVerifier::class, 'strict']);
        }

        $services->set(Monetico::class)
            ->args([
                service(Credentials::class),
                Environment::from($config['environment']),
                service(ClientInterface::class)->nullOnInvalid(),
                service(RequestFactoryInterface::class)->nullOnInvalid(),
                service(StreamFactoryInterface::class)->nullOnInvalid(),
                service(SealVerifier::class),
                $config['timezone'] === ''
                    ? null
                    : inline_service(DateTimeZone::class)->args([$config['timezone']]),
            ]);

        $services->set(NotificationVerifier::class)
            ->factory([service(Monetico::class), 'verifier']);
    }
}
