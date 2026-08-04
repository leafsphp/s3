<?php

use Leaf\FS\Bucket;

beforeEach(function () {
    \Leaf\Config::reset();

    $this->tmpDir = s3TestDir();
    resetBucket(localBucket($this->tmpDir), ['bucket' => 'testbucket']);

    Bucket::connections([
        's3' => [
            'bucket' => 'testbucket',
            'endpoint' => 'https://s3.example.com',
            'key' => 'key',
            'secret' => 'secret',
        ],
    ]);
});

afterEach(function () {
    resetBucket();
    \Leaf\Config::reset();
    deleteS3TestDir($this->tmpDir);
});

test('createFile writes nested path to the file itself, not a directory of the same name', function () {
    $result = Bucket::createFile('docs/note.txt', 'hello');

    expect($result)->not->toBeFalse();
    expect(is_file($this->tmpDir . '/docs/note.txt'))->toBeTrue();
    expect(file_get_contents($this->tmpDir . '/docs/note.txt'))->toBe('hello');
    // path-semantics regression: file must not land at docs/note.txt/note.txt
    expect(is_dir($this->tmpDir . '/docs/note.txt'))->toBeFalse();
    expect(file_exists($this->tmpDir . '/docs/note.txt/note.txt'))->toBeFalse();
});

test('createFile with a bare filename writes at the bucket root', function () {
    expect(Bucket::createFile('note.txt', 'root file'))->not->toBeFalse();
    expect(is_file($this->tmpDir . '/note.txt'))->toBeTrue();
    expect(file_get_contents($this->tmpDir . '/note.txt'))->toBe('root file');
});

test('createFile name option overrides the filename', function () {
    Bucket::createFile('docs/note.txt', 'renamed', ['name' => 'other.txt']);

    expect(is_file($this->tmpDir . '/docs/other.txt'))->toBeTrue();
    expect(file_exists($this->tmpDir . '/docs/note.txt'))->toBeFalse();
});

test('createFile on an existing file fails and records an error', function () {
    Bucket::createFile('docs/note.txt', 'first');

    expect(Bucket::createFile('docs/note.txt', 'second'))->toBeFalse();
    expect(file_get_contents($this->tmpDir . '/docs/note.txt'))->toBe('first');

    $errors = Bucket::errors();
    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('already exists');
});

test('createFile overwrite option replaces the content', function () {
    Bucket::createFile('docs/note.txt', 'first');
    Bucket::createFile('docs/note.txt', 'second', ['overwrite' => true]);

    expect(file_get_contents($this->tmpDir . '/docs/note.txt'))->toBe('second');
});

test('createFile rename option creates a second, renamed file', function () {
    Bucket::createFile('docs/note.txt', 'first');
    Bucket::createFile('docs/note.txt', 'second', ['rename' => true]);

    $files = glob($this->tmpDir . '/docs/*');
    expect($files)->toHaveCount(2);

    $renamed = array_values(array_filter($files, fn ($f) => basename($f) !== 'note.txt'));
    expect($renamed)->toHaveCount(1);
    expect(basename($renamed[0]))->toMatch('/^\d+_\w+_note\.txt$/');
    expect(file_get_contents($renamed[0]))->toBe('second');
    expect(file_get_contents($this->tmpDir . '/docs/note.txt'))->toBe('first');
});

test('upload puts a local file at destination/<basename>', function () {
    $src = s3TestDir() . '/local.txt';
    file_put_contents($src, 'uploaded content');

    expect(Bucket::upload($src, 'uploads'))->not->toBeFalse();
    expect(is_file($this->tmpDir . '/uploads/local.txt'))->toBeTrue();
    expect(file_get_contents($this->tmpDir . '/uploads/local.txt'))->toBe('uploaded content');

    deleteS3TestDir(dirname($src));
});

test('upload accepts a resource', function () {
    $src = s3TestDir() . '/stream.txt';
    file_put_contents($src, 'stream content');
    $resource = fopen($src, 'r');

    expect(Bucket::upload($resource, 'uploads'))->not->toBeFalse();
    expect(file_get_contents($this->tmpDir . '/uploads/stream.txt'))->toBe('stream content');

    deleteS3TestDir(dirname($src));
});

test('upload of a nonexistent path returns false with an error', function () {
    expect(Bucket::upload('/definitely/not/here.txt', 'uploads'))->toBeFalse();
    expect(implode(' ', Bucket::errors()))->toContain('does not exist');
});

test('upload with an empty string throws', function () {
    Bucket::upload('', 'uploads');
})->throws(InvalidArgumentException::class, 'Path cannot be empty');

test('upload with a non-string non-resource throws', function () {
    Bucket::upload(123, 'uploads');
})->throws(InvalidArgumentException::class, 'File must be a string or resource');

test('upload rename branch emits no warning when name option is absent', function () {
    $srcDir = s3TestDir();
    $src = $srcDir . '/note.txt';
    file_put_contents($src, 'first');

    Bucket::upload($src, 'uploads');

    $captured = [];
    set_error_handler(function ($errno, $errstr) use (&$captured) {
        $captured[] = $errstr;
        return true;
    });

    try {
        file_put_contents($src, 'second');
        $result = Bucket::upload($src, 'uploads', ['rename' => true]);
    } finally {
        restore_error_handler();
    }

    expect($captured)->toBeEmpty();
    expect($result)->not->toBeFalse();

    $files = glob($this->tmpDir . '/uploads/*');
    expect($files)->toHaveCount(2);

    $renamed = array_values(array_filter($files, fn ($f) => basename($f) !== 'note.txt'));
    expect(basename($renamed[0]))->toMatch('/^\d+_\w+_note\.txt$/');

    deleteS3TestDir($srcDir);
});

test('caller-supplied visibility reaches the flysystem write call', function () {
    // regression: options merge order previously forced every write public
    $recorder = new \Tests\RecordingFilesystem(
        new \League\Flysystem\Local\LocalFilesystemAdapter($this->tmpDir)
    );
    resetBucket($recorder, ['bucket' => 'testbucket']);

    Bucket::createFile('docs/note.txt', 'secret stuff', ['visibility' => 'private']);

    expect($recorder->writeConfigs)->toHaveCount(1);
    expect($recorder->writeConfigs[0]['visibility'])->toBe('private');
});

test('connect throws a named InvalidArgumentException for each missing key', function (string $missing) {
    $config = [
        'endpoint' => 'https://s3.example.com',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucketname',
    ];
    unset($config[$missing]);

    expect(fn () => Bucket::connect($config))
        ->toThrow(InvalidArgumentException::class, "missing the '$missing' key");
})->with(['endpoint', 'key', 'secret', 'bucket']);

test('connect with full config constructs offline and caches the bucket', function () {
    // S3Client construction and Config caching happen without any network I/O
    $bucket = Bucket::connect([
        'endpoint' => 'http://localhost:9999',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'offlinebucket',
    ]);

    expect($bucket)->toBeInstanceOf(Bucket::class);
    expect(Bucket::name())->toBe('offlinebucket');
    expect(\Leaf\Config::getStatic('storage.offlinebucket'))->not->toBeNull();
});

test('url resolves connections configured with the endpoint key', function () {
    // regression: url() previously only read a 'url' key from connections
    $url = Bucket::url('testbucket', 'file.txt');

    expect($url)->toBeString();
    expect($url)->toContain('file.txt');
    expect($url)->toContain('s3.example.com');
});

test('url returns null for an unknown bucket', function () {
    expect(Bucket::url('nope', 'file.txt'))->toBeNull();
});

test('temporaryUrl converts string expiry then fails only at URL generation', function () {
    // leafs/date is not installed, so the string goes through new DateTime().
    // The local adapter has no temporary URL generator, so reaching
    // UnableToGenerateTemporaryUrl proves the conversion path ran cleanly.
    expect(fn () => Bucket::temporaryUrl('file.txt', '+1 hour'))
        ->toThrow(\League\Flysystem\UnableToGenerateTemporaryUrl::class);
});

test('errors accumulates across failures', function () {
    Bucket::createFile('docs/note.txt', 'first');
    Bucket::createFile('docs/note.txt', 'dup');
    Bucket::upload('/missing/file.txt', 'uploads');

    expect(Bucket::errors())->toHaveCount(2);
});
