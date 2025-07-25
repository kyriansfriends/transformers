<?php

declare(strict_types=1);

namespace Codewithkyrian\Transformers\FFI;

use Codewithkyrian\PlatformPackageInstaller\Platform;
use FFI;
use FFI\CData;
use FFI\CType;
use RuntimeException;

use function Codewithkyrian\Transformers\Utils\joinPaths;

/**
 * NativeLibrary - Base class for loading platform-specific shared libraries
 * 
 * This abstract class provides common functionality for loading native libraries
 * based on the current platform. Child classes should implement getHeaderName()
 * and getLibraryName() to specify which library they represent.
 */
abstract class NativeLibrary
{
    /**
     * Platform-specific configurations
     */
    protected const PLATFORMS = [
        'linux-x86_64' => [
            'directory' => 'linux-x86_64', 
            'libraryTemplate' => 'lib{name}.so.{version}'
        ],
        'linux-arm64' => [
            'directory' => 'linux-arm64', 
            'libraryTemplate' => 'lib{name}.so.{version}'
        ],
        'darwin-x86_64' => [
            'directory' => 'macosx-x86_64', 
            'libraryTemplate' => 'lib{name}.{version}.dylib'
        ],
        'darwin-arm64' => [
            'directory' => 'macosx-arm64', 
            'libraryTemplate' => 'lib{name}.{version}.dylib'
        ],
        'windows-x86_64' => [
            'directory' => 'windows-x86_64', 
            'libraryTemplate' => '{name}-{version}.dll'
        ],
    ];

    /**
     * The FFI instance for this library
     */
    protected FFI $ffi;
    
    /**
     * Windows-specific kernel32 FFI instance (shared across all instances)
     */
    private static ?FFI $kernel32 = null;

    protected array $platformConfig;

    public function __construct(protected bool $loadLibrary = true)
    {
        $this->platformConfig = Platform::findBestMatch(self::PLATFORMS);
        
        if ($this->loadLibrary) {
            $this->loadLibrary();
            $this->configurePlatformSpecifics();
        }
    }

    public function __destruct()
    {
        $this->resetPlatformSpecifics();
    }

    public function getFfi(): FFI
    {
        return $this->ffi;
    }

    /**
     * Get the header file name for this library
     * 
     * @return string The header file name
     */
    abstract protected function getHeaderName(): string;

    /**
     * Get the library file name (without extension or version) for this library
     * 
     * @return string The base library file name
     */
    abstract protected function getLibraryName(): string;

    /**
     * Get the library version string for this library
     * 
     * @return string The library version
     */
    abstract protected function getLibraryVersion(): string;

    public function getHeaderPath(): string
    {
        return joinPaths($this->getIncludeDirectory(), "{$this->getHeaderName()}.h");
    }

    public function getLibraryPath(): string
    {
        $template = $this->platformConfig['libraryTemplate'];
        $name = $this->getLibraryName();
        $version = $this->getLibraryVersion();

        // Check if the template expects a 'lib' prefix and the name already has it
        if (str_contains($template, 'lib{name}') && str_starts_with($name, 'lib')) {
            // Remove the 'lib' prefix from the template placeholder before substitution
            $template = str_replace('lib{name}', '{name}', $template);
        }

        $filename = str_replace(['{name}', '{version}'], [$name, $version], $template);

        return joinPaths($this->getLibDirectory(), $filename);
    }

    public function getPlatformPath(): string
    {
        return joinPaths(dirname(__DIR__, 2), 'shared', $this->platformConfig['directory']);
    }

    public function getIncludeDirectory(): string
    {
        return joinPaths($this->getPlatformPath(), 'include');
    }

    public function getLibDirectory(): string
    {
        return joinPaths($this->getPlatformPath(), 'lib');
    }

    protected function loadLibrary() :void
    {
        if (!$this->platformConfig) {
            throw new RuntimeException("No matching platform configuration found");
        }

        $headerPath = $this->getHeaderPath();
        $libraryPath = $this->getLibraryPath();

        $this->ffi = FFI::cdef(file_get_contents($headerPath), $libraryPath);
    }

    /**
     * Creates a new instance of the specified type.
     *
     * @param string $type The type of the instance to create.
     * @param bool $owned Whether the instance should be owned. Default is true.
     * @param bool $persistent Whether the instance should be persistent. Default is false.
     *
     * @return CData|null The created instance, or null if the creation failed.
     * @throws Exception
     */
    public function new(string $type, bool $owned = true, bool $persistent = false): ?CData
    {
        return $this->ffi->new($type, $owned, $persistent);
    }

    /**
     * Casts a pointer to a different type.
     *
     * @param CType|string $type The type to cast to.
     * @param CData|int|float|bool|null $ptr The pointer to cast.
     *
     * @return ?CData The cast pointer, or null if the cast failed.
     * @throws Exception
     */
    public function cast(CType|string $type, CData|int|float|bool|null $ptr): ?CData
    {
        return $this->ffi->cast($type, $ptr);
    }

    /**
     * Retrieves the value of the enum constant with the given name.
     *
     * @param string $name The name of the enum constant.
     *
     * @return mixed The value of the enum constant.
     * @throws Exception
     */
    public function enum(string $name): mixed
    {
        return $this->ffi->{$name};
    }

    /**
     * Configure platform-specific settings
     * 
     * @return void
     */
    protected function configurePlatformSpecifics(): void
    {
        if (Platform::isWindows()) {
            $this->configureWindowsDllDirectory();
        }
    }

    /**
     * Reset platform-specific settings
     * 
     * @return void
     */
    protected function resetPlatformSpecifics(): void
    {
        if (Platform::isWindows()) {
            $this->resetWindowsDllDirectory();
        }
    }

    /**
     * Configure Windows DLL directory
     * 
     * @return void
     */
    protected function configureWindowsDllDirectory(): void
    {
        $platformConfig = Platform::findBestMatch(self::PLATFORMS);
        $libraryDir = $this->getLibDirectory($platformConfig['directory']);

        self::$kernel32 ??= FFI::cdef("
            int SetDllDirectoryA(const char* lpPathName);
            int SetDefaultDllDirectories(unsigned long DirectoryFlags);
        ", 'kernel32.dll');

        // Access the method through the FFI object
        self::$kernel32->{'SetDllDirectoryA'}($libraryDir);
    }

    /**
     * Reset Windows DLL directory
     * 
     * @return void
     */
    protected function resetWindowsDllDirectory(): void
    {
        if (self::$kernel32 !== null) {
            // Access the method through the FFI object
            self::$kernel32->{'SetDllDirectoryA'}(null);
        }
    }
}