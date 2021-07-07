<?php

/*
 * This file is part of the overtrue/flysystem-qiniu.
 * (c) overtrue <i@overtrue.me>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Qiniu;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Qiniu\Auth;
use Qiniu\Cdn\CdnManager;
use Qiniu\Http\Error;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

/**
 * Class QiniuAdapter.
 *
 * @author overtrue <i@overtrue.me>
 */
class QiniuAdapter implements FilesystemAdapter
{
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
     * @var \Qiniu\Cdn\CdnManager
     */
    protected $cdnManager;

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
     * @param Config $config Config object
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $mime = $config->get('mime', 'application/octet-stream');

        /**
         * @var Error|null $error
         */
        [, $error] = $this->getUploadManager()->put(
            $this->getAuthManager()->uploadToken($this->bucket),
            $path,
            $contents,
            null,
            $mime,
            $path
        );

        if ($error) {
            throw UnableToWriteFile::atLocation($path, $error->message());
        }
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $contents
     * @param Config $config Config object
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $data = '';

        while (!feof($contents)) {
            $data .= fread($contents, 1024);
        }

        $this->write($path, $data, $config);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        [, $error] = $this->getBucketManager()->rename($this->bucket, $source, $destination);
        if (!is_null($error)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * Copy a file.
     *
     * @param string $source
     * @param string $destination
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        [, $error] = $this->getBucketManager()->copy($this->bucket, $source, $this->bucket, $destination);
        if (!is_null($error)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path): void
    {
        [, $error] = $this->getBucketManager()->delete($this->bucket, $path);
        if (!is_null($error)) {
            throw UnableToDeleteFile::atLocation($path);
        }
    }

    /**
     * Delete a directory.
     *
     * @param string $path
     */
    public function deleteDirectory(string $path): void
    {
    }

    /**
     * Create a directory.
     *
     * @param string $path directory name
     * @param Config $config
     */
    public function createDirectory(string $path, Config $config): void
    {
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        [, $error] = $this->getBucketManager()->stat($this->bucket, $path);

        return is_null($error);
    }

    /**
     * Get resource url.
     *
     * @param string $path
     *
     * @return string
     */
    public function getUrl(string $path): string
    {
        $segments = $this->parseUrl($path);
        $query = empty($segments['query']) ? '' : '?' . $segments['query'];

        return $this->normalizeHost($this->domain) . ltrim(implode('/', array_map('rawurlencode', explode('/', $segments['path']))), '/') . $query;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return string
     */
    public function read(string $path): string
    {
        $result = file_get_contents($this->getUrl($path));
        if ($result === false) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $result;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        if (ini_get('allow_url_fopen')) {
            if ($result = fopen($this->getUrl($path), 'r')) {
                return $result;
            }
        }

        throw UnableToReadFile::fromLocation($path);
    }

    /**
     * List contents of a directory.
     *
     * @param string $path
     * @param bool $deep
     *
     * @return array
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $result = $this->getBucketManager()->listFiles($this->bucket, $path);

        foreach ($result[0]['items'] ?? [] as $files) {
            yield $this->normalizeFileInfo($files);
        }
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
     * @return FileAttributes
     */
    public function fileSize(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if ($meta->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }
        return $meta;
    }

    /**
     * Fetch url to bucket.
     *
     * @param string $path
     * @param string $url
     *
     * @return array|false
     */
    public function fetch($path, $url)
    {
        list($response, $error) = $this->getBucketManager()->fetch($url, $this->bucket, $path);

        if ($error) {
            return false;
        }

        return $response;
    }

    /**
     * Get private file download url.
     *
     * @param string $path
     * @param int $expires
     *
     * @return string
     */
    public function privateDownloadUrl($path, $expires = 3600)
    {
        return $this->getAuthManager()->privateDownloadUrl($this->getUrl($path), $expires);
    }

    /**
     * Refresh file cache.
     *
     * @param string|array $path
     *
     * @return array
     */
    public function refresh($path)
    {
        if (is_string($path)) {
            $path = [$path];
        }

        // 将 $path 变成完整的 url
        $urls = array_map([$this, 'getUrl'], $path);

        return $this->getCdnManager()->refreshUrls($urls);
    }

    /**
     * Get the mime-type of a file.
     *
     * @param string $path
     *
     * @return FileAttributes
     */
    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if ($meta->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return $meta;
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function lastModified(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if ($meta->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }
        return $meta;
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path);
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
     * @param CdnManager $manager
     *
     * @return $this
     */
    public function setCdnManager(CdnManager $manager)
    {
        $this->cdnManager = $manager;

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
     * @return \Qiniu\Cdn\CdnManager
     */
    public function getCdnManager()
    {
        return $this->cdnManager ?: $this->cdnManager = new CdnManager($this->getAuthManager());
    }

    /**
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the upload token.
     *
     * @param string|null $key
     * @param int $expires
     * @param string|null $policy
     * @param string|null $strictPolice
     *
     * @return string
     */
    public function getUploadToken($key = null, $expires = 3600, $policy = null, $strictPolice = null)
    {
        return $this->getAuthManager()->uploadToken($this->bucket, $key, $expires, $policy, $strictPolice);
    }

    /**
     * @param array $stats
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats)
    {
        return new FileAttributes(
            $stats['key'],
            $stats['fsize'] ?? null,
            null,
            isset($stats['putTime']) ? floor($stats['putTime'] / 10000000) : null,
            $stats['mimeType'] ?? null
        );
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

        return rtrim($domain, '/') . '/';
    }

    /**
     * Does a UTF-8 safe version of PHP parse_url function.
     *
     * @param string $url URL to parse
     *
     * @return mixed associative array or false if badly formed URL
     *
     * @see     http://us3.php.net/manual/en/function.parse-url.php
     * @since   11.1
     */
    protected static function parseUrl($url)
    {
        $result = false;

        // Build arrays of values we need to decode before parsing
        $entities = ['%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%24', '%2C', '%2F', '%3F', '%23', '%5B', '%5D', '%5C'];
        $replacements = ['!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '$', ',', '/', '?', '#', '[', ']', '/'];

        // Create encoded URL with special URL characters decoded so it can be parsed
        // All other characters will be encoded
        $encodedURL = str_replace($entities, $replacements, urlencode($url));

        // Parse the encoded URL
        $encodedParts = parse_url($encodedURL);

        // Now, decode each value of the resulting array
        if ($encodedParts) {
            foreach ($encodedParts as $key => $value) {
                $result[$key] = urldecode(str_replace($replacements, $entities, $value));
            }
        }

        return $result;
    }
}
