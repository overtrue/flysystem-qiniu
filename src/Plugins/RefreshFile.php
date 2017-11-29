<?php

namespace Overtrue\Flysystem\Qiniu\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

/**
 * Class RefreshFile
 *
 * @package \Overtrue\Flysystem\Qiniu\Plugins
 */
class RefreshFile extends AbstractPlugin
{
    public function getMethod()
    {
        return 'refresh';
    }

    public function handle($path = [])
    {
        $adapter = $this->filesystem->getAdapter();
        return $adapter->refresh($path);
    }
}
