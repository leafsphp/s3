<?php

namespace Leaf {
    // leafs/s3 uses \Leaf\Config but does not declare leafs/leaf as a
    // dependency. Provide a minimal polyfill matching the real behaviour
    // when the real class is not installed.
    if (!class_exists(\Leaf\Config::class)) {
        class Config
        {
            protected static array $items = [];
            protected static array $singletons = [];

            public static function set($key, $value = null): void
            {
                static::$items[$key] = $value;
            }

            public static function getStatic($key)
            {
                return static::$items[$key] ?? static::$singletons[$key] ?? null;
            }

            public static function singleton($key, callable $callback): void
            {
                static::$singletons[$key] = $callback;
            }

            public static function get($key)
            {
                if (array_key_exists($key, static::$items)) {
                    return static::$items[$key];
                }

                if (array_key_exists($key, static::$singletons)) {
                    return static::$items[$key] = (static::$singletons[$key])();
                }

                return null;
            }

            public static function reset(): void
            {
                static::$items = [];
                static::$singletons = [];
            }
        }
    }
}

namespace Tests {
    // Filesystem that records the $config array passed to write calls,
    // so tests can assert which options actually reach flysystem.
    class RecordingFilesystem extends \League\Flysystem\Filesystem
    {
        public array $writeConfigs = [];

        public function write(string $location, string $contents, array $config = []): void
        {
            $this->writeConfigs[] = $config;
            parent::write($location, $contents, $config);
        }

        public function writeStream(string $location, $contents, array $config = []): void
        {
            $this->writeConfigs[] = $config;
            parent::writeStream($location, $contents, $config);
        }
    }
}

namespace {
    function s3TestDir(): string
    {
        $dir = sys_get_temp_dir() . '/leaf-s3-tests-' . getmypid() . '/' . uniqid();
        mkdir($dir, 0777, true);

        return $dir;
    }

    function deleteS3TestDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    /**
     * Reset Bucket statics and optionally inject a filesystem (e.g. a
     * local-adapter Filesystem) into the protected static $bucket.
     */
    function resetBucket(?\League\Flysystem\Filesystem $filesystem = null, array $config = []): void
    {
        $ref = new ReflectionClass(\Leaf\FS\Bucket::class);

        foreach (['bucket' => $filesystem, 'config' => $config, 'errorsArray' => [], 'client' => null] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    function localBucket(string $dir): \League\Flysystem\Filesystem
    {
        return new \League\Flysystem\Filesystem(
            new \League\Flysystem\Local\LocalFilesystemAdapter($dir)
        );
    }
}
