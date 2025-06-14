<?php

declare(strict_types=1);

namespace Leaf\FS;

use Aws\S3\S3Client;
use League\Flysystem\Filesystem;

/**
 * S3-Compatible Bucket class
 * ---
 * This class is used to manage data in s3-compatible buckets.
 */
class Bucket
{
    protected static array $config = [];
    protected static ?S3Client $client = null;
    protected static ?Filesystem $bucket = null;
    protected static array $errorsArray = [];

    /**
     * Store all bucket connections
     * @param array $connections The connections to the buckets
     * @param string $default The default connection to use
     * @return void
     */
    public static function connections(array $connections, string $default = 's3')
    {
        \Leaf\Config::set('storage.connections', $connections);
        \Leaf\Config::set('storage.default', $default);
    }

    /**
     * Connect to the bucket
     */
    public static function connect(array $bucketConfig)
    {
        $adapter = new \League\Flysystem\AwsS3V3\AwsS3V3Adapter(
            static::$client = new S3Client([
                'region' => $bucketConfig['region'] ?? 'auto',
                'endpoint' => $bucketConfig['endpoint'],
                'use_path_style_endpoint' => $bucketConfig['use_path_style_endpoint'] ?? false,
                'version' => 'latest',
                'credentials' => (new \Aws\Credentials\Credentials(
                    $bucketConfig['key'],
                    $bucketConfig['secret']
                ))
            ]),
            $bucketConfig['bucket']
        );

        static::$config = $bucketConfig;
        static::$bucket = new Filesystem($adapter);

        return static::cache(new static);
    }

    /**
     * Return the bucket configuration
     * @return array
     */
    public static function config(): array
    {
        return static::$config;
    }

    /**
     * Return the bucket name
     * @return string|null
     */
    public static function name(): ?string
    {
        return static::$config['bucket'] ?? null;
    }

    /**
     * Generate a temporary URL
     * @param string $path The path to the file
     * @param \DateTime|\Leaf\Date|string $expires The expiration time
     * @return string
     */
    public static function temporaryUrl(string $path, $expires): string
    {
        if (is_string($expires)) {
            $expires = tick($expires);
        }

        if ($expires instanceof \Leaf\Date) {
            $expires = $expires->toDateTime();
        }

        return static::$bucket->temporaryUrl($path, $expires);
    }

    /**
     * Create a new file in the bucket
     * @param string $path The path to the file
     * @param string $content The content of the file
     * @param array $options The options for the file
     * @return string|false The URL of the file or false on failure
     */
    public static function createFile(string $path, string $content, array $options = [])
    {
        $options = array_merge($options, [
            'visibility' => 'public',
            'metadata' => [
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);

        $path = (new Path($path))->normalize();

        if (!static::$bucket->directoryExists($path)) {
            static::$bucket->createDirectory($path);
        }

        $fileName = $options['name'] ?? basename($path);
        $destination = (new Path($path))->join($fileName);

        if (static::$bucket->fileExists($destination)) {
            if ($options['overwrite'] ?? false) {
                static::$bucket->delete($destination);
            } else if ($options['rename'] ?? false) {
                $destination = str_replace(
                    $fileName,
                    time() . '_' . uniqid() . '_' . $fileName,
                    $destination
                );
            } else {
                static::$errorsArray[$path] = "File $destination already exists";
                return false;
            }
        }

        try {
            static::$bucket->write($destination, $content, $options);
        } catch (\League\Flysystem\FilesystemException | \League\Flysystem\UnableToWriteFile $exception) {
            static::$errorsArray[$path] = $exception->getMessage();
            return false;
        }

        try {
            $url = static::url(static::name(), $destination);
            // $url = static::$client->getObjectUrl(static::name(), $destination);
        } catch (\Throwable $th) {
            // upload successful but url generation failed (user would have to generate it manually)
            return true;
        }

        return $url;
    }

    /**
     * Upload a file to the bucket
     * @param string|resource $file The path to the file
     * @param string $destination The path to upload the file to
     * @param array $options The options for the file
     * @return string|false The URL of the file or false on failure
     */
    public static function upload($file, string $destination, array $options = [])
    {
        if (is_string($file)) {
            if (empty($file)) {
                throw new \InvalidArgumentException('Path cannot be empty');
            }

            if (!File::exists($file)) {
                static::$errorsArray[$file] = "File $file does not exist";
                return false;
            }

            if (!$resource = File::toResource($file, 'r+')) {
                static::$errorsArray[$file] = "Could not open file $file";
                return false;
            }
        } else {
            if (!is_resource($file)) {
                throw new \InvalidArgumentException('File must be a string or resource');
            }

            $resource = $file;
            $file = stream_get_meta_data($resource)['uri'];
        }

        $options = array_merge($options, [
            'visibility' => 'public',
            'metadata' => [
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);

        $destination = (new Path($destination))->normalize();

        if (!static::$bucket->directoryExists($destination)) {
            static::$bucket->createDirectory($destination);
        }

        $destination = (new Path($destination))->join($options['name'] ?? basename($file));

        if (static::$bucket->fileExists($destination)) {
            if ($options['overwrite'] ?? false) {
                static::$bucket->delete($destination);
            } else if ($options['rename'] ?? false) {
                $destination = str_replace(
                    $options['name'] ?? basename($file),
                    time() . '_' . uniqid() . '_' . $options['name'] ?? basename($file),
                    $destination
                );
            } else {
                static::$errorsArray[$file] = "File $destination already exists";
                return false;
            }
        }

        try {
            static::$bucket->writeStream($destination, $resource, $options);
        } catch (\League\Flysystem\FilesystemException | \League\Flysystem\UnableToWriteFile $exception) {
            static::$errorsArray[$file] = $exception->getMessage();
            return false;
        }

        fclose($resource);
        unset($resource);

        try {
            $url = static::url(static::name(), $destination);
            // $url = static::$client->getObjectUrl(static::name(), $destination);
        } catch (\Throwable $th) {
            // upload successful but url generation failed (user would have to generate it manually)
            return true;
        }

        return $url;
    }

    /**
     * Return current adapter
     * @return Filesystem|null
     */
    public static function getAdapter(): ?Filesystem
    {
        return static::$bucket;
    }

    /**
     * Return a configured connection
     * @param string $connection The connection to use
     * @return Bucket|null
     */
    public static function connection(string $connection): ?Bucket
    {
        return static::connect(\Leaf\Config::getStatic('storage.connections')[$connection]);
    }

    /**
     * Get the URL of a file
     * @param string $bucket The bucket to use
     * @param string $path The path to the file
     * @return string|null
     */
    public static function url(string $bucket, string $path): ?string
    {
        $bucketConfig = null;
        $connections = array_values(\Leaf\Config::getStatic('storage.connections') ?? []);

        foreach ($connections as $connection) {
            if ($connection['bucket'] === $bucket) {
                $bucketConfig = $connection;
                break;
            }
        }

        if (!$bucketConfig) {
            return null;
        }

        $client = new S3Client([
            'region' => $bucketConfig['region'] ?? 'auto',
            'endpoint' => $bucketConfig['url'],
            'use_path_style_endpoint' => $bucketConfig['use_path_style_endpoint'] ?? false,
            'version' => 'latest',
            'credentials' => (new \Aws\Credentials\Credentials(
                $bucketConfig['key'],
                $bucketConfig['secret']
            ))
        ]);

        $url = $client->getObjectUrl($bucketConfig['bucket'], $path);
        $url = str_replace($bucketConfig['bucket'] . '.', '', $url);

        return $url;
    }

    /**
     * Return a configured bucket
     * @param string $bucket
     * @return Bucket|null
     */
    public static function get($bucket = 's3'): ?Bucket
    {
        if (\Leaf\Config::getStatic("storage.{$bucket}")) {
            return static::$bucket = \Leaf\Config::get("storage.{$bucket}");
        }

        $connections = array_values(\Leaf\Config::getStatic('storage.connections') ?? []);

        foreach ($connections as $connection) {
            if ($connection['bucket'] === $bucket) {
                return static::connect($connection);
            }
        }

        return null;
    }

    /**
     * Cache a configured bucket
     * @param Bucket $bucket The bucket to cache
     * @return Bucket
     */
    public static function cache(Bucket $bucket): Bucket
    {
        \Leaf\Config::singleton("storage.{$bucket->name()}", function () use ($bucket) {
            return $bucket;
        });

        return $bucket;
    }

    /**
     * Return the errors array
     * @return array
     */
    public static function errors(): array
    {
        return static::$errorsArray;
    }
}
