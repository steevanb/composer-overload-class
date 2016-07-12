<?php

namespace steevanb\ComposerOverloadClass;

use Composer\Script\Event;

class OverloadClass
{
    const EXTRA_OVERLOAD_CACHE_DIR = 'composer-overload-cache-dir';
    const EXTRA_OVERLOAD_CLASS = 'composer-overload-class';
    const NAMESPACE_PREFIX = 'ComposerOverloadClass';

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
     * @param string $filePath
     * @return string
     */
    protected static function generateProxy($cacheDir, $fullyQualifiedClassName, $filePath)
    {
        $php = static::assertFileContent($filePath, $fullyQualifiedClassName);
        $classNameParts = array_merge(array(static::NAMESPACE_PREFIX), explode('\\', $fullyQualifiedClassName));
        $className = array_pop($classNameParts);
        foreach ($classNameParts as $part) {
            $cacheDir .= DIRECTORY_SEPARATOR . $part;
            if (is_dir($cacheDir) === false) {
                mkdir($cacheDir);
            }
        }

        file_put_contents($cacheDir . DIRECTORY_SEPARATOR . basename($filePath), $php);
    }

    /**
     * @param string $filePath
     * @param string $fullyQualifiedClassName
     * @return string
     * @throws \Exception
     */
    protected static function assertFileContent($filePath, $fullyQualifiedClassName)
    {
        if (is_readable($filePath) === false) {
            throw new \Exception('File "' . $filePath . '" does not exists, or is not readable.');
        }

        $php = file_get_contents($filePath);
        $namespace = substr($fullyQualifiedClassName, 0, strrpos($fullyQualifiedClassName, '\\'));
        $className = substr($fullyQualifiedClassName, strrpos($fullyQualifiedClassName, '\\') + 1);
        $nextIsNamespace = false;
        $namespaceFound = null;
        $nextIsClass = false;
        $classFound = [];
        $phpNamespace = null;
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $nextIsNamespace = true;
                } elseif ($token[0] === T_CLASS) {
                    $nextIsClass = true;
                }
                if ($nextIsNamespace) {
                    $phpNamespace .= $token[1];
                    if ($token[0] === T_NS_SEPARATOR || $token[0] === T_STRING) {
                        $namespaceFound .= $token[1];
                    }
                } elseif ($nextIsClass && $token[0] === T_STRING) {
                    $classFound[] = $token[1];
                    $nextIsClass = false;
                }
            } elseif ($nextIsNamespace && $token === ';') {
                $phpNamespace .= $token;
                if ($namespaceFound !== $namespace) {
                    $message = 'Expected namespace "' . $namespace . '", found "' . $namespaceFound . '" ';
                    $message .= 'in "' . $filePath . '".';
                    throw new \Exception($message);
                }
                $nextIsNamespace = false;
            }
        }

        if (count($classFound) !== 1) {
            throw new \Exception('Expected 1 class, found "' . implode(', ', $classFound) . '" in "' . $filePath . '".');
        } elseif ($classFound[0] !== $className) {
            $message = 'Expected "' . $className . '" class, found "' . $classFound[0] . '" ';
            $message .= 'in "' . $filePath . '".';
            throw new \Exception($message);
        }

        $php = str_replace($phpNamespace, 'namespace ' . static::NAMESPACE_PREFIX . '\\' . $namespaceFound . ';', $php);

        return $php;
    }
}
