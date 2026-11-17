<?php
declare(strict_types=1);
namespace Fixture;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Ktulchu\AppLog\AuditSubscriber;
use Ktulchu\AppLog\RawJsonType;
use Ktulchu\AppLog\RawJsonbType;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    public function registerBundles(): iterable { return [new FrameworkBundle(), new DoctrineBundle(), new DoctrineMigrationsBundle()]; }
    public function getProjectDir(): string { return dirname(__DIR__); }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'disposable-test-fixture', 'test' => true]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => '%env(DATABASE_URL)%', 'types' => ['app_log_json' => RawJsonType::class, 'app_log_jsonb' => RawJsonbType::class]],
                'orm' => ['auto_generate_proxy_classes' => true, 'mappings' => ['Fixture' => ['type' => 'attribute', 'dir' => __DIR__ . '/Entity', 'prefix' => 'Fixture\\Entity']]],
            ]);
            $container->loadFromExtension('doctrine_migrations', [
                'migrations_paths' => ['Ktulchu\\AppLog\\Migrations' => dirname(__DIR__) . '/vendor/ktulchu/symfony-app-log/migrations'],
            ]);
            $definition = $container->register(AuditSubscriber::class);
            foreach (['onFlush', 'postFlush', 'onClear'] as $event) {
                $definition->addTag('doctrine.event_listener', ['event' => $event, 'connection' => 'default']);
            }
        });
    }
}
