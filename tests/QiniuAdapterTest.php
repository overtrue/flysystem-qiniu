<?php

/*
 * This file is part of the overtrue/flysystem-qiniu.
 * (c) overtrue <i@overtrue.me>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Qiniu\Tests;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Overtrue\Flysystem\Qiniu\QiniuAdapter;
use PHPUnit\Framework\TestCase;
use Qiniu\Auth;
use Qiniu\Http\Error;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

/**
 * Class QiniuAdapterTest.
 */
class QiniuAdapterTest extends TestCase
{
    public function setUp(): void
    {
        require_once __DIR__ . '/helpers.php';
    }

    public function qiniuProvider()
    {
        $adapter = Mockery::mock(QiniuAdapter::class, ['accessKey', 'secretKey', 'bucket', 'domain.com'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $authManager = \Mockery::mock('stdClass');
        $bucketManager = \Mockery::mock('stdClass');
        $uploadManager = \Mockery::mock('stdClass');
        $cdnManager = \Mockery::mock('stdClass');

        $authManager->allows()->uploadToken('bucket')->andReturns('token');

        $adapter->allows([
            'getAuthManager' => $authManager,
            'getUploadManager' => $uploadManager,
            'getBucketManager' => $bucketManager,
            'getCdnManager' => $cdnManager,
        ]);

        return [
            [$adapter, compact('authManager', 'bucketManager', 'uploadManager', 'cdnManager')],
        ];
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testWrite($adapter, $managers)
    {
        $managers['uploadManager']->expects()->put('token', 'foo/bar.md', 'content', null, 'application/octet-stream', 'foo/bar.md')
            ->andReturns(['response', null], ['response', new Error('foo/bar.md', (object)['error' => 'Not Found.'])])
            ->twice();

        $this->assertNull($adapter->write('foo/bar.md', 'content', new Config()));
        $this->expectException(UnableToWriteFile::class);
        $adapter->write('foo/bar.md', 'content', new Config());
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testWriteWithMime($adapter, $managers)
    {
        $managers['uploadManager']->expects()->put('token', 'foo/bar.md', 'http://httpbin/org', null, 'application/redirect302', 'foo/bar.md')
            ->andReturns(['response', null], ['response', new Error('foo/bar.md', (object)['error' => 'Not Found.'])])
            ->twice();

        $this->assertNull(
            $adapter->write(
                'foo/bar.md',
                'http://httpbin/org',
                new Config(['mime' => 'application/redirect302'])
            )
        );
        $this->expectException(UnableToWriteFile::class);
        $adapter->write('foo/bar.md', 'http://httpbin/org', new Config(['mime' => 'application/redirect302']));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testWriteStream($adapter)
    {
        $adapter->expects()->write('foo.md', '', Mockery::type(Config::class))
            ->once();

        $this->assertNull($adapter->writeStream('foo.md', tmpfile(), new Config()));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testMove($adapter, $managers)
    {
        $managers['bucketManager']->expects()
            ->rename('bucket', 'old.md', 'new.md')
            ->andReturn([true, null], [false, new Error('', '')])
            ->twice();

        $this->assertNull($adapter->move('old.md', 'new.md', new Config()));

        $this->expectException(UnableToMoveFile::class);
        $adapter->move('old.md', 'new.md', new Config());
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testCopy($adapter, $managers)
    {
        $managers['bucketManager']->expects()
            ->copy('bucket', 'old.md', 'bucket', 'new.md')
            ->andReturn([true, null], [false, new Error('', '')])
            ->twice();

        $this->assertNull($adapter->copy('old.md', 'new.md', new Config()));

        $this->expectException(UnableToCopyFile::class);
        $adapter->copy('old.md', 'new.md', new Config());
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testDelete($adapter, $managers)
    {
        $managers['bucketManager']->expects()
            ->delete('bucket', 'file.md')
            ->andReturn([true, null], [false, new Error('', '')])
            ->twice();

        $this->assertNull($adapter->delete('file.md'));
        $this->expectException(UnableToDeleteFile::class);
        $adapter->delete('file.md');
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testHas($adapter, $managers)
    {
        $managers['bucketManager']->expects()->stat('bucket', 'file.md')
            ->andReturns(['response', null], ['response', true])
            ->twice();

        $this->assertTrue($adapter->fileExists('file.md'));
        $this->assertFalse($adapter->fileExists('file.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testRead($adapter)
    {
        $this->assertSame(
            \Overtrue\Flysystem\Qiniu\file_get_contents('http://domain.com/foo/file.md'),
            $adapter->read('foo/file.md')
        );

        // urlencode
        $this->assertSame(
            \Overtrue\Flysystem\Qiniu\file_get_contents('http://domain.com/foo/%E6%96%87%E4%BB%B6%E5%90%8D.md'),
            $adapter->read('foo/文件名.md')
        );

        // urlencode with query
        $this->assertSame(
            \Overtrue\Flysystem\Qiniu\file_get_contents('http://domain.com/foo/%E6%96%87%E4%BB%B6%E5%90%8D.md?info=yes&type=xxx'),
            $adapter->read('foo/文件名.md?info=yes&type=xxx')
        );
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testReadStream($adapter)
    {
        $GLOBALS['result_of_ini_get'] = true;

        $this->assertSame(
            \Overtrue\Flysystem\Qiniu\fopen('http://domain.com/foo/file.md', 'r'),
            $adapter->readStream('foo/file.md')
        );

        $this->assertSame(
            \Overtrue\Flysystem\Qiniu\fopen('http://domain.com/foo/%E6%96%87%E4%BB%B6%E5%90%8D.md', 'r'),
            $adapter->readStream('foo/文件名.md')
        );

        $GLOBALS['result_of_ini_get'] = false;
        $this->expectException(UnableToReadFile::class);
        $adapter->readStream('foo/file.md');
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testListContents($adapter, $managers)
    {
        $managers['bucketManager']->expects()->listFiles('bucket', 'path/to/list')
            ->andReturn([['items' => [
                [
                    'key' => 'foo.md',
                    'putTime' => 123 * 10000000,
                    'fsize' => 123,
                ],
                [
                    'key' => 'bar.md',
                    'putTime' => 124 * 10000000,
                    'fsize' => 124,
                ],
            ]]])
            ->twice();

        $res = $adapter->listContents('path/to/list', true);
        $asserts = [
            new FileAttributes('foo.md', 123, null, 123.0),
            new FileAttributes('bar.md', 124, null, 124.0),
        ];
        foreach ($res as $item) {
            $this->assertEquals(array_shift($asserts), $item);
        }
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetMetadata($adapter, $managers)
    {
        $managers['bucketManager']->expects()->stat('bucket', 'file.md')
            ->andReturns([
                [
                    'putTime' => 124 * 10000000,
                    'fsize' => 124,
                ],
            ])
            ->once();

        $this->assertEquals(new FileAttributes('file.md', 124, null, 124), $adapter->getMetadata('file.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetSize($adapter)
    {
        $adapter->expects()->getMetadata('foo.md')->andReturns(new FileAttributes('file.md', 123))->once();

        $this->assertSame(123, $adapter->fileSize('foo.md')->fileSize());
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testPrivateDownloadUrl($adapter, $managers)
    {
        $managers['authManager']->expects()->privateDownloadUrl('http://domain.com/url', 3600)->andReturn('url');
        $this->assertSame('url', $adapter->privateDownloadUrl('url'));

        $managers['authManager']->expects()->privateDownloadUrl('http://domain.com/url', 7200)->andReturn('url');
        $this->assertSame('url', $adapter->privateDownloadUrl('url', 7200));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testRefresh($adapter, $managers)
    {
        $managers['cdnManager']->expects()->refreshUrls(['http://domain.com/url'])->andReturn('url');
        $this->assertSame('url', $adapter->refresh('url'));
        $this->assertSame('url', $adapter->refresh(['url']));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetUploadToken($adapter, $managers)
    {
        $managers['authManager']->expects()->uploadToken('bucket', null, 3600, null, null)->andReturn('token');
        $this->assertSame('token', $adapter->getUploadToken());

        $managers['authManager']->expects()->uploadToken('bucket', 'key', 3600, null, null)->andReturn('token');
        $this->assertSame('token', $adapter->getUploadToken('key'));

        $managers['authManager']->expects()->uploadToken('bucket', 'key', 7200, null, null)->andReturn('token');
        $this->assertSame('token', $adapter->getUploadToken('key', 7200));

        $managers['authManager']->expects()->uploadToken('bucket', 'key', 7200, 'foo', null)->andReturn('token');
        $this->assertSame('token', $adapter->getUploadToken('key', 7200, 'foo'));

        $managers['authManager']->expects()->uploadToken('bucket', 'key', 7200, 'foo', 'bar')->andReturn('token');
        $this->assertSame('token', $adapter->getUploadToken('key', 7200, 'foo', 'bar'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetMimetype($adapter, $managers)
    {
        $managers['bucketManager']->expects()->stat('bucket', 'foo.md')
            ->andReturns([
                [
                    'mimeType' => 'application/xml',
                ],
            ], [])
            ->twice();

        $this->assertEquals(new FileAttributes('foo.md', null, null, null, 'application/xml'), $adapter->mimeType('foo.md'));

        $this->expectException(UnableToRetrieveMetadata::class);
        $adapter->mimeType('foo.md');
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetTimestamp($adapter)
    {
        $adapter->expects()->getMetadata('foo.md')->andReturns(new FileAttributes('foo.md', null, null, 123))->once();

        $this->assertSame(123, $adapter->lastModified('foo.md')->lastModified());
    }

    public function testSettersGetters()
    {
        $authManager = new Auth('ak', 'sk');
        $uploadManager = new UploadManager();
        $bucketManager = new BucketManager($authManager);

        $adapter = new QiniuAdapter('ak', 'sk', 'bucket', 'domain.com');
        $adapter->setUploadManager($uploadManager)->setBucketManager($bucketManager)->setAuthManager($authManager);
        $this->assertSame($authManager, $adapter->getAuthManager());
        $this->assertSame($uploadManager, $adapter->getUploadManager());
        $this->assertSame($bucketManager, $adapter->getBucketManager());

        $adapter = new QiniuAdapter('ak', 'sk', 'bucket', 'domain.com');

        $this->assertInstanceOf(Auth::class, $adapter->getAuthManager());
        $this->assertInstanceOf(UploadManager::class, $adapter->getUploadManager());
        $this->assertInstanceOf(BucketManager::class, $adapter->getBucketManager());
    }
}
