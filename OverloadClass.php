<?php

namespace steevanb\ComposerOverloadClass;

use Composer\Script\Event;
use Composer\IO\IOInterface;

class OverloadClass
{
    const EXTRA_OVERLOAD_CACHE_DIR = 'composer-overload-cache-dir';
    const EXTRA_OVERLOAD_CACHE_DIR_DEV = 'composer-overload-cache-dir-dev';
    const EXTRA_OVERLOAD_CLASS = 'composer-overload-class';
    const EXTRA_OVERLOAD_CLASS_DEV = 'composer-overload-class-dev';
    const EXTRA_OVERLOAD_DUPLICATE_ORIGINAL_FILE = 'duplicate-original-file';
    const NAMESPACE_PREFIX = 'ComposerOverloadClass';

    public static function overload(Event $event)
    {
        static::defineAutoloadExcludeFromClassmap($event);
        static::defineAutoloadFiles($event);
    }

    protected static function defineAutoloadExcludeFromClassmap(Event $event)
    {
        $originalFiles = ($event->isDevMode())
            ? [static::EXTRA_OVERLOAD_CLASS, static::EXTRA_OVERLOAD_CLASS_DEV]
            : [static::EXTRA_OVERLOAD_CLASS];
        $overloadFiles = ($event->isDevMode()) ? [] : [static::EXTRA_OVERLOAD_CLASS_DEV];
        $autoload = static::getAutoload($event);
        $extra = $event->getComposer()->getPackage()->getExtra();

        foreach ($originalFiles as $env) {
            if (array_key_exists($env, $extra)) {
                foreach ($extra[$env] as $className => $infos) {
                    $autoload['exclude-from-classmap'][] = $infos['original-file'];
                }
            }
        }

        foreach ($overloadFiles as $env) {
            if (array_key_exists($env, $extra)) {
                foreach ($extra[$env] as $className => $infos) {
                    $autoload['exclude-from-classmap'][] = $infos['overload-file'];
                }
            }
        }

        $event->getComposer()->getPackage()->setAutoload($autoload);
    }

    protected static function defineAutoloadFiles(Event $event)
    {
        $extra = $event->getComposer()->getPackage()->getExtra();

        if ($event->isDevMode()) {
            $envs = [static::EXTRA_OVERLOAD_CLASS, static::EXTRA_OVERLOAD_CLASS_DEV];
            $cacheDirKey = static::EXTRA_OVERLOAD_CACHE_DIR_DEV;
            if (array_key_exists($cacheDirKey, $extra) === false) {
                $cacheDirKey = static::EXTRA_OVERLOAD_CACHE_DIR;
            }
        } else {
            $envs = [static::EXTRA_OVERLOAD_CLASS];
            $cacheDirKey = static::EXTRA_OVERLOAD_CACHE_DIR;
        }
        if (array_key_exists($cacheDirKey, $extra) === false) {
            throw new \Exception('You must specify extra/' . $cacheDirKey . ' in composer.json');
        }
        $cacheDir = $extra[$cacheDirKey];

        $autoload = static::getAutoload($event);

        foreach ($envs as $extraKey) {
            if (array_key_exists($extraKey, $extra)) {
                foreach ($extra[$extraKey] as $className => $infos) {
                    if (
                        array_key_exists(static::EXTRA_OVERLOAD_DUPLICATE_ORIGINAL_FILE, $infos) === false
                        || $infos[static::EXTRA_OVERLOAD_DUPLICATE_ORIGINAL_FILE] === false
                    ) {
                        static::generateProxy(
                            $cacheDir,
                            $className,
                            $infos['original-file'],
                            $event->getIO()
                        );
                    }
                    $autoload['files'][$className] = $infos['overload-file'];

                    $message = '<info>' . $infos['original-file'] . '</info>';
                    $message .= ' is overloaded by <comment>' . $infos['overload-file'] . '</comment>';
                    $event->getIO()->write($message, true, IOInterface::VERBOSE);
                }
            }
        }

        $event->getComposer()->getPackage()->setAutoload($autoload);
    }

    /** @return array */
    protected static function getAutoload(Event $event)
    {
        $return = $event->getComposer()->getPackage()->getAutoload();
        if (array_key_exists('files', $return) === false) {
            $return['files'] = array();
        }
        if (array_key_exists('exclude-from-classmap', $return) === false) {
            $return['exclude-from-classmap'] = array();
        }

        return $return;
    }

    /** @param string $path */
    protected static function createDirectories($path, IOInterface $io)
    {
        if (is_dir($path) === false) {
            $io->write('Creating directory <info>' . $path . '</info>.', true, IOInterface::VERBOSE);

            $createdPath = null;
            foreach (explode('/', $path) as $directory) {
                if (is_dir($createdPath . $directory) === false) {
                    mkdir($createdPath . $directory);
                }
                $createdPath .= $directory . '/';
            }
        }
    }

    /**
     * @param string $cacheDir
     * @param string $fullyQualifiedClassName
     * @param string $filePath
     * @return string
     */
    protected static function generateProxy($cacheDir, $fullyQualifiedClassName, $filePath, IOInterface $io)
    {
        $php = static::getPhpForDuplicatedFile($filePath, $fullyQualifiedClassName);
        $classNameParts = array_merge(array(static::NAMESPACE_PREFIX), explode('\\', $fullyQualifiedClassName));
        array_pop($classNameParts);
        $finalCacheDir = $cacheDir . '/' . implode('/', $classNameParts);
        static::createDirectories($finalCacheDir, $io);

        $overloadedFilePath = $finalCacheDir . '/' . basename($filePath);
        file_put_contents($overloadedFilePath, $php);

        $io->write(
            'Generate proxy for <info>'
                . $fullyQualifiedClassName
                . '</info> in <comment>'
                . $overloadedFilePath
                . '</comment>',
            true,
            IOInterface::VERBOSE
        );
    }

    /**
     * @param string $filePath
     * @param string $fullyQualifiedClassName
     * @return string
     */
    protected static function getPhpForDuplicatedFile($filePath, $fullyQualifiedClassName)
    {
        if (is_readable($filePath) === false) {
            throw new \Exception('File "' . $filePath . '" does not exists, or is not readable.');
        }

        $phpLines = file($filePath);
        $namespace = substr($fullyQualifiedClassName, 0, strrpos($fullyQualifiedClassName, '\\'));
        $nextIsNamespace = false;
        $namespaceFound = null;
        $classesFound = [];
        $phpCodeForNamespace = null;
        $namespaceLine = null;
        $uses = [];
        $addUses = [];
        $isGlobalUse = true;
        $lastUseLine = null;
        $tokens = token_get_all(implode(null, $phpLines));
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $nextIsNamespace = true;
                    $namespaceLine = $token[2];
                } elseif ($isGlobalUse && $token[0] === T_CLASS) {
                    $classesFound[] = static::getClassNameFromTokens($tokens, $index + 1);
                    $isGlobalUse = false;
                } elseif ($token[0] === T_EXTENDS) {
                    static::addUse(static::getClassNameFromTokens($tokens, $index + 1), $namespaceFound, $uses, $addUses);
                } elseif (
                    $isGlobalUse
                    && $token[0] === T_USE
                    // remove "use function foo" syntax
                    && $tokens[$index + 2][0] === T_STRING
                ) {
                    $uses[] = static::getClassNameFromTokens($tokens, $index + 1);
                    $lastUseLine = $token[2];
                }

                if ($nextIsNamespace) {
                    $phpCodeForNamespace .= $token[1];
                    if ($token[0] === T_NS_SEPARATOR || $token[0] === T_STRING) {
                        $namespaceFound .= $token[1];
                    }
                }
            } elseif ($nextIsNamespace && $token === ';') {
                $phpCodeForNamespace .= $token;
                if ($namespaceFound !== $namespace) {
                    $message = 'Expected namespace "' . $namespace . '", found "' . $namespaceFound . '" ';
                    $message .= 'in "' . $filePath . '".';
                    throw new \Exception($message);
                }
                $nextIsNamespace = false;
            }
        }

        static::assertOnlyRightClassFound($classesFound, $fullyQualifiedClassName, $filePath);
        static::replaceNamespace($namespaceFound, $phpCodeForNamespace, $phpLines, $namespaceLine);
        static::addUsesInPhpLines($addUses, $phpLines, ($lastUseLine === null ? $namespaceLine : $lastUseLine));

        return implode(null, $phpLines);
    }

    /**
     * @param string $fullyQualifiedClassName
     * @param string $filePath
     */
    protected static function assertOnlyRightClassFound(array $classFound, $fullyQualifiedClassName, $filePath)
    {
        $className = substr($fullyQualifiedClassName, strrpos($fullyQualifiedClassName, '\\') + 1);
        if (count($classFound) !== 1) {
            throw new \Exception('Expected 1 class, found "' . implode(', ', $classFound) . '" in "' . $filePath . '".');
        } elseif ($classFound[0] !== $className) {
            $message = 'Expected "' . $className . '" class, found "' . $classFound[0] . '" ';
            $message .= 'in "' . $filePath . '".';
            throw new \Exception($message);
        }
    }

    /**
     * @param string $namespace
     * @param string $phpCodeForNamespace
     * @param array $phpLines
     * @param int $namespaceLine
     */
    protected static function replaceNamespace($namespace, $phpCodeForNamespace, &$phpLines, $namespaceLine)
    {
        $phpLines[$namespaceLine - 1] = str_replace(
            $phpCodeForNamespace,
            'namespace ' . static::NAMESPACE_PREFIX . '\\' . $namespace . ';',
            $phpLines[$namespaceLine - 1]
        );
    }

    /**
     * @param int $index
     * @return string
     */
    protected static function getClassNameFromTokens(array &$tokens, $index)
    {
        $return = null;
        do {
            if (
                is_array($tokens[$index])
                && (
                    $tokens[$index][0] === T_STRING
                    || $tokens[$index][0] === T_NS_SEPARATOR
                )
            ) {
                $return .= $tokens[$index][1];
            }

            $index++;
            $continue =
                is_array($tokens[$index])
                && (
                    $tokens[$index][0] === T_STRING
                    || $tokens[$index][0] === T_NS_SEPARATOR
                    || $tokens[$index][0] === T_WHITESPACE
                );
        } while ($continue);

        if ($return === null) {
            throw new \Exception('Class not found in tokens.');
        }

        return $return;
    }

    /**
     * @param string $className
     * @param string $namespace
     */
    protected static function addUse($className, $namespace, array $uses, array &$addUses)
    {
        if (substr($className, 0, 1) !== '\\') {
            $alreadyInUses = false;
            foreach ($uses as $use) {
                if (substr($use, strrpos($use, '\\') + 1) === $className) {
                    $alreadyInUses = true;
                }
            }

            if ($alreadyInUses === false) {
                $addUses[] = $namespace . '\\' . $className;
            }
        }
    }

    /** @param int $line */
    protected static function addUsesInPhpLines(array $addUses, array &$phpLines, $line)
    {
        $linesBefore = ($line > 0) ? array_slice($phpLines, 0, $line) : [];
        $linesAfter = array_slice($phpLines, $line);

        array_walk($addUses, function(&$addUse) {
            $addUse = 'use ' . $addUse . ';' . "\n";
        });

        $phpLines = array_merge($linesBefore, $addUses, $linesAfter);
    }
}
