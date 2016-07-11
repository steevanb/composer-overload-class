<?php

namespace steevanb\ComposerOverloadClass;

use Composer\Script\Event;

class OverloadClass
{
    const EXTRA_OVERLOAD_CACHE_DIR = 'composer-overload-cache-dir';
    const EXTRA_OVERLOAD_CLASS = 'composer-overload-class';

    /**
     * @param Event $event
     */
    public static function overload(Event $event)
    {
        $extra = $event->getComposer()->getPackage()->getExtra();
        if (array_key_exists(static::EXTRA_OVERLOAD_CLASS, $extra)) {
            $autoload = $event->getComposer()->getPackage()->getAutoload();
            if (array_key_exists('classmap', $autoload) === false) {
                $autoload['classmap'] = array();
            }

            foreach ($extra[static::EXTRA_OVERLOAD_CLASS] as $className => $fileName) {
                $autoload['classmap'][$className] = static::generateProxy(
                    $extra[static::EXTRA_OVERLOAD_CACHE_DIR],
                    $className,
                    $fileName
                );
            }

            $event->getComposer()->getPackage()->setAutoload($autoload);
        }
    }

    /**
     * @param string $cacheDir
     * @param string $fullyQualifiedClassName
     * @param string $fileName
     */
    protected static function generateProxy($cacheDir, $fullyQualifiedClassName, $fileName)
    {
        $php = file_get_contents($fileName);
        $classNameParts = explode('\\', $fullyQualifiedClassName);
        $className = array_pop($classNameParts);
        foreach ($classNameParts as $part) {
            $cacheDir .= DIRECTORY_SEPARATOR . $part;
            var_dump($cacheDir);
        }
        var_dump($className);
    }

    /**
     * @param string $php
     * @param string $fullyQualifiedClassName
     * @param string $fileName
     * @throws \Exception
     */
    protected static function assertFileContent($php, $fullyQualifiedClassName, $fileName)
    {
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && $token[0] === T_CLASS) {
                $message = 'You can\'t overload "' . $fullyQualifiedClassName . '", ';
                $message .= ', cause "' . $fileName . '" contains this class, and at least another one.';
                throw new \Exception($message);
            }
        }
    }
}
