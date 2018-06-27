<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit45e520d638647603f2b98fac5dc8f96e
{
    public static $files = array (
        'decc78cc4436b1292c6c0d151b19445c' => __DIR__ . '/..' . '/phpseclib/phpseclib/phpseclib/bootstrap.php',
    );

    public static $prefixLengthsPsr4 = array (
        'p' => 
        array (
            'phpseclib\\' => 10,
        ),
        'H' => 
        array (
            'Hhxsv5\\SSE\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'phpseclib\\' => 
        array (
            0 => __DIR__ . '/..' . '/phpseclib/phpseclib/phpseclib',
        ),
        'Hhxsv5\\SSE\\' => 
        array (
            0 => __DIR__ . '/..' . '/hhxsv5/php-sse/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit45e520d638647603f2b98fac5dc8f96e::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit45e520d638647603f2b98fac5dc8f96e::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
