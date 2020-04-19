<?php declare(strict_types=1);

namespace WyriHaximus\Broadcast\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflector\ClassReflector;
use Roave\BetterReflection\Reflector\Exception\IdentifierNotFound;
use Roave\BetterReflection\SourceLocator\Type\Composer\Factory\MakeLocatorForComposerJsonAndInstalledJson;
use Roave\BetterReflection\SourceLocator\Type\Composer\Psr\Exception\InvalidPrefixMapping;
use Rx\Observable;
use Throwable;
use WyriHaximus\Broadcast\Marker\Listener;
use function ApiClients\Tools\Rx\observableFromArray;
use function array_key_exists;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function is_array;
use function is_string;
use function microtime;
use function round;
use function rtrim;
use function Safe\chmod;
use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\mkdir;
use function Safe\sprintf;
use function str_replace;
use function strpos;
use function var_export;
use function WyriHaximus\getIn;
use function WyriHaximus\iteratorOrArrayToArray;
use function WyriHaximus\listClassesInDirectories;
use const DIRECTORY_SEPARATOR;
use const WyriHaximus\Constants\Numeric\ONE;
use const WyriHaximus\Constants\Numeric\ZERO;

final class Installer implements PluginInterface, EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [ScriptEvents::PRE_AUTOLOAD_DUMP => 'findEventListeners'];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        // does nothing, see getSubscribedEvents() instead.
    }

    /**
     * Called before every dump autoload, generates a fresh PHP class.
     */
    public static function findEventListeners(Event $event): void
    {
        $start    = microtime(true);
        $io       = $event->getIO();
        $composer = $event->getComposer();
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/react/promise/src/functions_include.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/api-clients/rx/src/functions_include.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/iterator-or-array-to-array/src/functions_include.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/list-classes-in-directory/src/functions_include.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/string-get-in/src/functions_include.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/constants/src/Numeric/constants_include.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/igorw/get-in/src/get_in.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/jetbrains/phpstorm-stubs/PhpStormStubsMap.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/thecodingmachine/safe/generated/filesystem.php';
        /** @psalm-suppress UnresolvableInclude */
        require_once $composer->getConfig()->get('vendor-dir') . '/thecodingmachine/safe/generated/strings.php';

        $io->write('<info>wyrihaximus/broadcast:</info> Locating listeners');

        $listeners = self::getRegisteredListeners($composer, $io);

        $io->write('<info>wyrihaximus/broadcast:</info> Found ' . count($listeners) . ' event(s)');

        $classContents = sprintf(
            str_replace(
                "['%s']",
                '%s',
                file_get_contents(
                    self::locateRootPackageInstallPath($composer->getConfig(), $composer->getPackage()) . '/etc/AbstractListenerProvider.php'
                )
            ),
            var_export($listeners, true)
        );
        $installPath   = self::locateRootPackageInstallPath($composer->getConfig(), $composer->getPackage())
            . '/src/Generated/AbstractListenerProvider.php';

        file_put_contents($installPath, $classContents);
        chmod($installPath, 0664);

        $io->write(sprintf(
            '<info>wyrihaximus/broadcast:</info> Generated static abstract listeners provider in %s second(s)',
            round(microtime(true) - $start, 2)
        ));
    }

    /**
     * Find the location where to put the generate PHP class in.
     */
    private static function locateRootPackageInstallPath(
        Config $composerConfig,
        RootPackageInterface $rootPackage
    ): string {
        // You're on your own
        if ($rootPackage->getName() === 'wyrihaximus/broadcast') {
            return dirname($composerConfig->get('vendor-dir'));
        }

        return $composerConfig->get('vendor-dir') . '/wyrihaximus/broadcast';
    }

    /**
     * @return array<string, array<array{class: string, method: string, static: bool}>>
     */
    private static function getRegisteredListeners(Composer $composer, IOInterface $io): array
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        retry:
        try {
            $classReflector = new ClassReflector(
                (new MakeLocatorForComposerJsonAndInstalledJson())(dirname($vendorDir), (new BetterReflection())->astLocator()),
            );
        } catch (InvalidPrefixMapping $invalidPrefixMapping) {
            mkdir(explode('" is not a', explode('" for prefix "', $invalidPrefixMapping->getMessage())[1])[0]);
            goto retry;
        }

        $result     = [];
        $packages   = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packages[] = $composer->getPackage();
        observableFromArray($packages)->filter(static function (PackageInterface $package): bool {
            return count($package->getAutoload()) > 0;
        })->filter(static function (PackageInterface $package): bool {
            return getIn($package->getExtra(), 'wyrihaximus.broadcast.has-listeners', false);
        })->filter(static function (PackageInterface $package): bool {
            return array_key_exists('classmap', $package->getAutoload()) || array_key_exists('psr-4', $package->getAutoload());
        })->flatMap(static function (PackageInterface $package) use ($vendorDir): Observable {
            $packageName = $package->getName();
            $autoload    = $package->getAutoload();
            $paths       = [];
            foreach (['classmap', 'psr-4'] as $item) {
                if (! array_key_exists($item, $autoload)) {
                    continue;
                }

                foreach ($autoload[$item] as $path) {
                    if (is_string($path)) {
                        if ($package instanceof RootPackageInterface) {
                            $paths[] = dirname($vendorDir) . DIRECTORY_SEPARATOR . $path;
                        } else {
                            $paths[] = $vendorDir . DIRECTORY_SEPARATOR . $packageName . DIRECTORY_SEPARATOR . $path;
                        }
                    }

                    if (! is_array($path)) {
                        continue;
                    }

                    foreach ($path as $p) {
                        if ($package instanceof RootPackageInterface) {
                            $paths[] = dirname($vendorDir) . DIRECTORY_SEPARATOR . $p;
                        } else {
                            $paths[] = $vendorDir . DIRECTORY_SEPARATOR . $packageName . DIRECTORY_SEPARATOR . $p;
                        }
                    }
                }
            }

            return observableFromArray($paths);
        })->map(static function (string $path): string {
            return rtrim($path, '/');
        })->filter(static function (string $path): bool {
            return file_exists($path);
        })->toArray()->flatMap(static function (array $paths): Observable {
            return observableFromArray(
                iteratorOrArrayToArray(
                    listClassesInDirectories(...$paths)
                )
            );
        })->flatMap(static function (string $class) use ($classReflector, $io): Observable {
            try {
                /** @psalm-suppress PossiblyUndefinedVariable */
                return observableFromArray([
                    (static function (ReflectionClass $reflectionClass): ReflectionClass {
                        $reflectionClass->getInterfaces();
                        $reflectionClass->getMethods();

                        return $reflectionClass;
                    })($classReflector->reflect($class)),
                ]);
            } catch (IdentifierNotFound $identifierNotFound) {
                $io->write(sprintf(
                    '<info>wyrihaximus/broadcast:</info> Error while reflecting "<fg=cyan>%s</>": <fg=yellow>%s</>',
                    $class,
                    $identifierNotFound->getMessage()
                ));
            }

            return observableFromArray([]);
        })->filter(static function (ReflectionClass $class): bool {
            return $class->isInstantiable();
        })->filter(static function (ReflectionClass $class): bool {
            return $class->implementsInterface(Listener::class);
        })->flatMap(static function (ReflectionClass $class): Observable {
            $events = [];

            foreach ($class->getMethods() as $method) {
                if (! $method->isPublic()) {
                    continue;
                }

                if (strpos($method->getName(), '__') === ZERO) {
                    continue;
                }

                if ($method->getNumberOfParameters() !== ONE) {
                    continue;
                }

                $events[] = [
                    'event' => (string) $method->getParameters()[0]->getType(),
                    'class' => $class->getName(),
                    'method' => $method->getName(),
                    'static' => $method->isStatic(),
                ];
            }

            return observableFromArray($events);
        })->toArray()->toPromise()->then(static function (array $flatEvents) use (&$result, $io): void {
            $io->write(sprintf('<info>wyrihaximus/broadcast:</info> Found %s listener(s)', count($flatEvents)));
            $events = [];

            foreach ($flatEvents as $flatEvent) {
                $events[(string) $flatEvent['event']][] = [
                    'class' => $flatEvent['class'],
                    'method' => $flatEvent['method'],
                    'static' => $flatEvent['static'],
                ];
            }

            $result = $events;
        })->then(null, static function (Throwable $throwable) use ($io): void {
            $io->write(sprintf('<info>wyrihaximus/broadcast:</info> Unexpected error: <fg=red>%s</>', $throwable->getMessage()));
        });

        return $result;
    }
}