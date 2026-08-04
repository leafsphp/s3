<?php

use Leaf\FS\Bucket;

/*
 * Read/write/delete and metadata against a bucket, driven through a local
 * flysystem adapter so no network is involved.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/leaf-bucket-ops-' . getmypid();

    if (!is_dir($this->dir)) {
        mkdir($this->dir, 0777, true);
    }

    resetBucket(localBucket($this->dir));
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
    resetBucket();
});

test('write then read a file', function () {
    expect(Bucket::write('notes/hello.txt', 'hi there'))->toBeTrue();
    expect(Bucket::read('notes/hello.txt'))->toBe('hi there');
});

test('exists reports presence correctly', function () {
    Bucket::write('a.txt', 'x');

    expect(Bucket::exists('a.txt'))->toBeTrue();
    expect(Bucket::exists('missing.txt'))->toBeFalse();
});

test('write replaces existing content', function () {
    Bucket::write('a.txt', 'first');
    Bucket::write('a.txt', 'second');

    expect(Bucket::read('a.txt'))->toBe('second');
});

test('delete removes a file', function () {
    Bucket::write('gone.txt', 'x');

    expect(Bucket::delete('gone.txt'))->toBeTrue();
    expect(Bucket::exists('gone.txt'))->toBeFalse();
});

test('reading a missing file fails without throwing', function () {
    expect(Bucket::read('nope.txt'))->toBeFalse();
    expect(Bucket::errors())->not->toBeEmpty();
});

test('copy and move within the bucket', function () {
    Bucket::write('src.txt', 'payload');

    expect(Bucket::copy('src.txt', 'copy.txt'))->toBeTrue();
    expect(Bucket::read('copy.txt'))->toBe('payload');
    expect(Bucket::exists('src.txt'))->toBeTrue();

    expect(Bucket::move('src.txt', 'moved.txt'))->toBeTrue();
    expect(Bucket::exists('src.txt'))->toBeFalse();
    expect(Bucket::read('moved.txt'))->toBe('payload');
});

test('size and lastModified return real metadata', function () {
    Bucket::write('sized.txt', '12345');

    expect(Bucket::size('sized.txt'))->toBe(5);
    expect(Bucket::lastModified('sized.txt'))->toBeInt();
});

test('mimeType is detected', function () {
    Bucket::write('page.html', '<h1>hi</h1>');

    expect(Bucket::mimeType('page.html'))->toContain('html');
});

test('list returns files in the bucket', function () {
    Bucket::write('docs/a.txt', 'a');
    Bucket::write('docs/b.txt', 'b');

    $files = Bucket::list('docs');

    expect($files)->toHaveCount(2);
    expect($files)->toContain('docs/a.txt');
});
