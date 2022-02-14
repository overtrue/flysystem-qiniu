<?php

/*
 * This file is part of the overtrue/flysystem-qiniu.
 * (c) overtrue <i@overtrue.me>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Qiniu;

function file_get_contents($path): string
{
    return "contents of {$path}";
}

function fopen($path, $mode): string
{
    return "resource of {$path} with mode {$mode}";
}

$GLOBALS['result_of_ini_get'] = true;

function ini_get()
{
    return $GLOBALS['result_of_ini_get'];
}
