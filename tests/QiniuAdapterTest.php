<?php

/*
 * This file is part of the overtrue/flysystem-qiniu.
 * (c) overtrue <i@overtrue.me>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Qiniu\Tests;

use League\Flysystem\Config;
use Mockery;
use Overtrue\Flysystem\Qiniu\QiniuAdapter;
use PHPUnit\Framework\TestCase;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

/**
 * Class QiniuAdapterTest.
 */
class QiniuAdapterTest extends TestCase
{
    public function setUp()
    {
        require_once __DIR__.'/helpers.php';
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
        $managers['uploadManager']->expects()->put('token', 'foo/bar.md', 'content', null, 'application/octet-stream')
            ->andReturns(['response', false], ['response', true])
            ->twice();

        $this->assertSame('response', $adapter->write('foo/bar.md', 'content', new Config()));
        $this->assertFalse($adapter->write('foo/bar.md', 'content', new Config()));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testWriteWithMime($adapter, $managers)
    {
        $managers['uploadManager']->expects()->put('token', 'foo/bar.md', 'http://httpbin/org', null, 'application/redirect302')
            ->andReturns(['response', false], ['response', true])
            ->twice();

        $this->assertSame(
            'response',
            $adapter->write(
                'foo/bar.md',
                'http://httpbin/org',
                new Config(['mime' => 'application/redirect302'])
            )
        );
        $this->assertFalse($adapter->write('foo/bar.md', 'http://httpbin/org', new Config(['mime' => 'application/redirect302'])));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testWriteStream($adapter)
    {
        $adapter->expects()->write('foo.md', '', Mockery::type(Config::class))
            ->andReturns(true, false)
            ->twice();

        $result = $adapter->writeStream('foo.md', tmpfile(), new Config());
        $this->assertSame('foo.md', $result['path']);

        $this->assertFalse($adapter->writeStream('foo.md', tmpfile(), new Config()));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testUpdate($adapter)
    {
        $adapter->expects()->delete('foo.md')->once();
        $adapter->expects()->write('foo.md', 'content', Mockery::type(Config::class))->andReturns(true)->once();

        $this->assertTrue($adapter->update('foo.md', 'content', new Config()));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testUpdateStream($adapter)
    {
        $resource = tmpfile();
        $adapter->expects()->delete('foo.md')->once();
        $adapter->expects()->writeStream('foo.md', $resource, Mockery::type(Config::class))->andReturns(true)->once();

        $this->assertTrue($adapter->updateStream('foo.md', $resource, new Config()));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testRename($adapter, $managers)
    {
        $managers['bucketManager']->expects()
            ->rename('bucket', 'old.md', 'new.md')
            ->andReturn(false, null)
            ->twice();

        $this->assertFalse($adapter->rename('old.md', 'new.md'));
        $this->assertTrue($adapter->rename('old.md', 'new.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testCopy($adapter, $managers)
    {
        $managers['bucketManager']->expects()
            ->copy('bucket', 'old.md', 'bucket', 'new.md')
            ->andReturn(false, null)
            ->twice();

        $this->assertFalse($adapter->copy('old.md', 'new.md'));
        $this->assertTrue($adapter->copy('old.md', 'new.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testDelete($adapter, $managers)
    {
        $managers['bucketManager']->expects()
            ->delete('bucket', 'file.md')
            ->andReturn(false, null)
            ->twice();

        $this->assertFalse($adapter->delete('file.md'));
        $this->assertTrue($adapter->delete('file.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testDeleteDir($adapter)
    {
        $this->assertTrue($adapter->deleteDir('foo/bar'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testCreateDir($adapter)
    {
        $this->assertSame([
            'path' => 'foo/bar',
            'type' => 'dir',
        ], $adapter->createDir('foo/bar', new Config()));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testHas($adapter, $managers)
    {
        $managers['bucketManager']->expects()->stat('bucket', 'file.md')
            ->andReturns(['response', false], ['response', true])
            ->twice();

        $this->assertTrue($adapter->has('file.md'));
        $this->assertFalse($adapter->has('file.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testRead($adapter)
    {
        $this->assertSame([
            'contents' => \Overtrue\Flysystem\Qiniu\file_get_contents('http://domain.com/foo/file.md'),
            'path' => 'foo/file.md',
        ], $adapter->read('foo/file.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testReadStream($adapter)
    {
        $GLOBALS['result_of_ini_get'] = true;

        $this->assertSame([
            'stream' => \Overtrue\Flysystem\Qiniu\fopen('http://domain.com/foo/file.md', 'r'),
            'path' => 'foo/file.md',
        ], $adapter->readStream('foo/file.md'));

        $GLOBALS['result_of_ini_get'] = false;
        $this->assertFalse($adapter->readStream('foo/file.md'));
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

        $this->assertSame([
            [
                'type' => 'file',
                'path' => 'foo.md',
                'timestamp' => 123.0,
                'size' => 123,
            ],
            [
                'type' => 'file',
                'path' => 'bar.md',
                'timestamp' => 124.0,
                'size' => 124,
            ],
        ], $adapter->listContents('path/to/list'));
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

        $this->assertSame([
            'type' => 'file',
            'path' => 'file.md',
            'timestamp' => 124.0,
            'size' => 124,
        ], $adapter->getMetadata('file.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetSize($adapter)
    {
        $adapter->expects()->getMetadata('foo.md')->andReturns('meta-data')->once();

        $this->assertSame('meta-data', $adapter->getSize('foo.md'));
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
            ], false)
            ->twice();

        $this->assertSame(['mimetype' => 'application/xml'], $adapter->getMimetype('foo.md'));
        $this->assertFalse($adapter->getMimetype('foo.md'));
    }

    /**
     * @dataProvider qiniuProvider
     */
    public function testGetTimestamp($adapter)
    {
        $adapter->expects()->getMetadata('foo.md')->andReturns('meta-data')->once();

        $this->assertSame('meta-data', $adapter->getTimestamp('foo.md'));
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
