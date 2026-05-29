<?php
/**
 * This file is part of ICalSync plugin for FacturaScripts
 * 
 * Simple PSR-4 autoloader for bundled sabre/dav dependencies.
 * Only used when FacturaScripts vendor/ doesn't have them.
 */

namespace FacturaScripts\Plugins\ICalSync\Vendor;

class Autoloader
{
    /** @var array<string, string> Namespace prefix → base directory mapping */
    private static array $prefixes = [];

    /** @var bool Whether this autoloader has been registered */
    private static bool $registered = false;

    /**
     * Register the autoloader with SPL.
     * Only registers if sabre/dav classes are NOT already loadable
     * (i.e., FS vendor/ directory doesn't have them).
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        // Only register if the FS vendor doesn't already have sabre/dav
        if (class_exists('Sabre\\DAV\\Client', false)) {
            return;
        }

        // Skip if loaded from FS vendor (composer autoload)
        $vendorBase = dirname(__DIR__, 1) . '/vendor/sabre';

        self::$prefixes = [
            'Sabre\\Event\\'    => $vendorBase . '/event/lib/',
            'Sabre\\Uri\\'      => $vendorBase . '/uri/lib/',
            'Sabre\\Xml\\'      => $vendorBase . '/xml/lib/',
            'Sabre\\HTTP\\'     => $vendorBase . '/http/lib/',
            'Sabre\\VObject\\'  => $vendorBase . '/vobject/lib/',
            'Sabre\\CalDAV\\'   => $vendorBase . '/dav/lib/CalDAV/',
            'Sabre\\CardDAV\\'  => $vendorBase . '/dav/lib/CardDAV/',
            'Sabre\\DAVACL\\'   => $vendorBase . '/dav/lib/DAVACL/',
            'Sabre\\DAV\\'      => $vendorBase . '/dav/lib/DAV/',
        ];

        spl_autoload_register([self::class, 'loadClass'], true, true);

        // Load sabre functions (not autoloaded by PSR-4)
        self::loadFunctions();

        self::$registered = true;
    }

    /**
     * Load sabre package function files.
     */
    private static function loadFunctions(): void
    {
        $vendorBase = dirname(__DIR__, 1) . '/vendor/sabre';
        $functions = [
            '/uri/lib/functions.php',
            '/event/lib/Loop/functions.php',
            '/event/lib/Promise/functions.php',
            '/event/lib/coroutine.php',
            '/http/lib/functions.php',
            '/xml/lib/Deserializer/functions.php',
            '/xml/lib/Serializer/functions.php',
        ];
        foreach ($functions as $file) {
            $path = $vendorBase . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * PSR-4 autoload implementation.
     *
     * @param string $class Fully-qualified class name
     */
    public static function loadClass(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                continue;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }

    /**
     * Check if bundled sabre libraries are available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        $vendorBase = dirname(__DIR__, 1) . '/vendor/sabre/dav/lib/DAV/Client.php';
        return file_exists($vendorBase);
    }
}
