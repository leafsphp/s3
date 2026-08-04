<?php

use Leaf\FS\Bucket;
use Leaf\FS\File;

/*
 * The point of withBucket() is that storage paths work the same whether
 * they're local or in a bucket. These drive Leaf\FS\File (what storage()
 * proxies to) with `bucket://` paths and assert they reach the bucket.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/leaf-fs-integration-' . getmypid();

    if (!is_dir($this->dir)) {
        mkdir($this->dir, 0777, true);
    }

    // no named connection registered: File should fall back to the bucket
    // that's already connected, which is the local adapter injected here
    resetBucket(localBucket($this->dir));
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
    resetBucket();
});

test('File::create writes to the bucket, not the local disk', function () {
    File::create('s3://reports/q1.txt', 'revenue');

    expect(Bucket::read('reports/q1.txt'))->toBe('revenue');

    // it landed in the bucket's root, not in a literal "s3:" directory
    expect(file_get_contents($this->dir . '/reports/q1.txt'))->toBe('revenue');
    expect(is_dir(getcwd() . '/s3:'))->toBeFalse();
});

test('File::read reads back through a bucket path', function () {
    Bucket::write('reports/q1.txt', 'revenue');

    expect(File::read('s3://reports/q1.txt'))->toBe('revenue');
});

test('File::exists checks the bucket', function () {
    Bucket::write('there.txt', 'x');

    expect(File::exists('s3://there.txt'))->toBeTrue();
    expect(File::exists('s3://not-there.txt'))->toBeFalse();
});

test('File::delete removes from the bucket', function () {
    Bucket::write('temp.txt', 'x');

    expect(File::delete('s3://temp.txt'))->toBeTrue();
    expect(Bucket::exists('temp.txt'))->toBeFalse();
});

test('File::write replaces bucket content', function () {
    Bucket::write('notes.txt', 'old');

    expect(File::write('s3://notes.txt', 'new'))->toBeTrue();
    expect(Bucket::read('notes.txt'))->toBe('new');
});

test('File::write accepts a callback with the current contents', function () {
    Bucket::write('log.txt', 'line one');

    File::write('s3://log.txt', fn ($current) => $current . "\nline two");

    expect(Bucket::read('log.txt'))->toBe("line one\nline two");
});

test('File::size and lastModified read bucket metadata', function () {
    Bucket::write('sized.txt', '1234567890');

    expect(File::size('s3://sized.txt'))->toBe(10);
    expect(File::size('s3://sized.txt', 'kb'))->toBeFloat();
    expect(File::lastModified('s3://sized.txt'))->toBeInt();
});

test('File::mimeType reads from the bucket', function () {
    Bucket::write('doc.html', '<p>hi</p>');

    expect(File::mimeType('s3://doc.html'))->toContain('html');
});

test('local paths are untouched by the bucket routing', function () {
    $local = $this->dir . '/plain.txt';
    File::create($local, 'local content');

    expect(File::read($local))->toBe('local content');
    expect(File::exists($local))->toBeTrue();
});
