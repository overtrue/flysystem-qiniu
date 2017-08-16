<?php

/*
 * This file is part of the overtrue/flysystem-qiniu.
 * (c) overtrue <i@overtrue.me>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Qiniu;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

/**
 * Class QiniuAdapter
 *
 * @author overtrue <i@overtrue.me>
 */
class QiniuAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string
     */
    protected $accessKey;

    /**
     * @var string
     */
    protected $secretKey;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $domain;

    /**
     * @var \Qiniu\Auth
     */
    protected $authManager;

    /**
     * @var \Qiniu\Storage\UploadManager
     */
    protected $uploadManager;

    /**
     * @var \Qiniu\Storage\BucketManager
     */
    protected $bucketManager;

    /**
     * QiniuAdapter constructor.
     *
     * @param string $accessKey
     * @param string $secretKey
     * @param string $bucket
     * @param string $domain
     */
    public function __construct($accessKey, $secretKey, $bucket, $domain)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->bucket = $bucket;
        $this->domain = $domain;
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        list($response, $error) = $this->getUploadManager()->put(
            $this->getAuthManager()->uploadToken($this->bucket),
            $path,
            $contents
        );

        if ($error) {
            return false;
        }

        return $response;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        $contents = '';

        while (!feof($resource)) {
            $contents .= fread($resource, 1024);
        }

        $response = $this->write($path, $contents, $config);

        if ($response === false) {
            return $response;
        }

        return compact('path');
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        $this->delete($path);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        $this->delete($path);

        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newPath
     *
     * @return bool
     */
    public function rename($path, $newPath)
    {
        $response = $this->getBucketManager()->rename($this->bucket, $path, $newPath);

        return is_null($response);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newPath
     *
     * @return bool
     */
    public function copy($path, $newPath)
    {
        $response = $this->getBucketManager()->copy($this->bucket, $path, $this->bucket, $newPath);

        return is_null($response);
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $response = $this->getBucketManager()->delete($this->bucket, $path);

        return is_null($response);
    }

    /**
     * Delete a directory.
     *
     * @param string $directory
     *
     * @return bool
     */
    public function deleteDir($directory)
    {
        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $directory directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($directory, Config $config)
    {
        return ['path' => $directory, 'type' => 'dir'];
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        list($response, $error) = $this->getBucketManager()->stat($this->bucket, $path);

        return !$error || is_array($response);
    }

    /**
     * Get resource url.
     *
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        return $this->normalizeHost($this->domain).$path;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $contents = file_get_contents($this->getUrl($path));

        return compact('contents', 'path');
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        if (ini_get('allow_url_fopen')) {
            $stream = fopen($this->normalizeHost($this->domain).$path, 'r');

            return compact('stream', 'path');
        }

        return false;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];

        $result = $this->getBucketManager()->listFiles($this->bucket, $directory);

        foreach ($result[0] as $files) {
            $list[] = $this->normalizeFileInfo($files);
        }

        return $list;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $result = $this->getBucketManager()->stat($this->bucket, $path);
        $result[0]['key'] = $path;

        return $this->normalizeFileInfo($result[0]);
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mime-type of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimeType($path)
    {
        $response = $this->getBucketManager()->stat($this->bucket, $path);

        if (empty($response[0]['mimeType'])) {
            return false;
        }

        return ['mimetype' => $response[0]['mimeType']];
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param \Qiniu\Storage\BucketManager $manager
     *
     * @return $this
     */
    public function setBucketManager(BucketManager $manager)
    {
        $this->bucketManager = $manager;

        return $this;
    }

    /**
     * @param \Qiniu\Storage\UploadManager $manager
     *
     * @return $this
     */
    public function setUploadManager(UploadManager $manager)
    {
        $this->uploadManager = $manager;

        return $this;
    }

    /**
     * @param \Qiniu\Auth $manager
     *
     * @return $this
     */
    public function setAuthManager(Auth $manager)
    {
        $this->authManager = $manager;

        return $this;
    }

    /**
     * @return \Qiniu\Storage\BucketManager
     */
    public function getBucketManager()
    {
        return $this->bucketManager ?: $this->bucketManager = new BucketManager($this->getAuthManager());
    }

    /**
     * @return \Qiniu\Auth
     */
    public function getAuthManager()
    {
        return $this->authManager ?: $this->authManager = new Auth($this->accessKey, $this->secretKey);
    }

    /**
     * @return \Qiniu\Storage\UploadManager
     */
    public function getUploadManager()
    {
        return $this->uploadManager ?: $this->uploadManager = new UploadManager();
    }

    /**
     * Get the upload token.
     *
     * @param string|null $key
     * @param int  $expires
     * @param bool $policy
     *
     * @return string
     */
    public function getUploadToken($key = null, $expires = 3600, $policy = true)
    {
        return $this->getAuthManager()->uploadToken($this->bucket, $key, $expires, $policy);
    }

    /**
     * @param array $stats
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats)
    {
        return [
            'type' => 'file',
            'path' => $stats['key'],
            'timestamp' => floor($stats['putTime'] / 10000000),
            'size' => $stats['fsize'],
        ];
    }

    /**
     * @param string $domain
     *
     * @return string
     */
    protected function normalizeHost($domain)
    {
        if (0 !== stripos($domain, 'https://') && 0 !== stripos($domain, 'http://')) {
            $domain = "http://{$domain}";
        }

        return rtrim($domain, '/').'/';
    }
}
