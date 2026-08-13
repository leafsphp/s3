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
     * Normalize a bucket object key — object keys are always forward-slash,
     * whatever directory separator the host OS uses
     */
    protected static function key($path): string
    {
        return str_replace('\\', '/', (new Path($path))->normalize());
    }

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
        foreach (['endpoint', 'key', 'secret', 'bucket'] as $required) {
            if (empty($bucketConfig[$required])) {
                throw new \InvalidArgumentException("Bucket connection is missing the '$required' key");
            }
        }

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
            // tick() ships with leafs/date, which is optional here
            $expires = function_exists('tick') ? tick($expires) : new \DateTime($expires);
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
        // defaults first so caller options win: the old order forced
        // every upload public no matter what visibility was passed
        $options = array_merge([
            'visibility' => 'public',
            'metadata' => [
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ], $options);

        $path = static::key($path);

        // $path is the file itself: the directory to ensure is its parent.
        // Treating $path as a directory produced docs/note.txt/note.txt
        $directory = dirname($path);

        if ($directory !== '.' && !static::$bucket->directoryExists($directory)) {
            static::$bucket->createDirectory($directory);
        }

        $fileName = $options['name'] ?? basename($path);
        $destination = $directory === '.' ? $fileName : static::key((new Path($directory))->join($fileName));

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

        // defaults first so caller options win: the old order forced
        // every upload public no matter what visibility was passed
        $options = array_merge([
            'visibility' => 'public',
            'metadata' => [
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ], $options);

        $destination = static::key($destination);

        if (!static::$bucket->directoryExists($destination)) {
            static::$bucket->createDirectory($destination);
        }

        $destination = static::key((new Path($destination))->join($options['name'] ?? basename($file)));

        if (static::$bucket->fileExists($destination)) {
            if ($options['overwrite'] ?? false) {
                static::$bucket->delete($destination);
            } else if ($options['rename'] ?? false) {
                $destination = str_replace(
                    $options['name'] ?? basename($file),
                    time() . '_' . uniqid() . '_' . ($options['name'] ?? basename($file)),
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
     * Check if a file exists in the bucket
     * @param string $path The path to the file
     */
    public static function exists(string $path): bool
    {
        try {
            return static::$bucket->fileExists(static::key($path));
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Read a file from the bucket
     * @param string $path The path to the file
     * @return string|false The file contents, or false on failure
     */
    public static function read(string $path)
    {
        try {
            return static::$bucket->read(static::key($path));
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Write to a file in the bucket, replacing whatever is there
     * @param string $path The path to the file
     * @param string $content The content to write
     */
    public static function write(string $path, string $content): bool
    {
        try {
            static::$bucket->write(static::key($path), $content);

            return true;
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Delete a file from the bucket
     * @param string $path The path to the file
     */
    public static function delete(string $path): bool
    {
        try {
            static::$bucket->delete(static::key($path));

            return true;
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Copy a file within the bucket
     * @param string $source The path to copy from
     * @param string $destination The path to copy to
     */
    public static function copy(string $source, string $destination): bool
    {
        try {
            static::$bucket->copy(
                static::key($source),
                static::key($destination)
            );

            return true;
        } catch (\Throwable $exception) {
            static::$errorsArray[$source] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Move a file within the bucket
     * @param string $source The path to move from
     * @param string $destination The path to move to
     */
    public static function move(string $source, string $destination): bool
    {
        try {
            static::$bucket->move(
                static::key($source),
                static::key($destination)
            );

            return true;
        } catch (\Throwable $exception) {
            static::$errorsArray[$source] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Get the size of a file in the bucket, in bytes
     * @param string $path The path to the file
     * @return int|false
     */
    public static function size(string $path)
    {
        try {
            return static::$bucket->fileSize(static::key($path));
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Get the last modified time of a file in the bucket, as a unix timestamp
     * @param string $path The path to the file
     * @return int|false
     */
    public static function lastModified(string $path)
    {
        try {
            return static::$bucket->lastModified(static::key($path));
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * Get the mime type of a file in the bucket
     * @param string $path The path to the file
     * @return string|false
     */
    public static function mimeType(string $path)
    {
        try {
            return static::$bucket->mimeType(static::key($path));
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return false;
        }
    }

    /**
     * List the files in a bucket directory
     * @param string $path The directory to list
     * @param bool $recursive List files in sub-directories too?
     * @return array<int, string>
     */
    public static function list(string $path = '', bool $recursive = false): array
    {
        try {
            return static::$bucket
                ->listContents(static::key($path), $recursive)
                ->filter(function ($item) {
                    return $item->isFile();
                })
                ->map(function ($item) {
                    return $item->path();
                })
                ->toArray();
        } catch (\Throwable $exception) {
            static::$errorsArray[$path] = $exception->getMessage();

            return [];
        }
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
        $connections = \Leaf\Config::getStatic('storage.connections') ?? [];

        if (isset($connections[$connection])) {
            return static::connect($connections[$connection]);
        }

        // no connection registered under that name: fall back to whatever
        // is already connected, so a manual connect() still works with
        // withBucket() paths
        if (static::$bucket) {
            return new static();
        }

        static::$errorsArray['bucket'] = "No bucket connection named '$connection'. Register it with Bucket::connections([...]) or connect to a bucket first.";

        return null;
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
            // connections are configured with 'endpoint'; 'url' kept as a
            // fallback for configs that used the old key
            'endpoint' => $bucketConfig['endpoint'] ?? $bucketConfig['url'],
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
