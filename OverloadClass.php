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
    const NAMESPACE_PREFIX = 'ComposerOverloadClass';

    /**
     * @param Event $event
     * @throws \Exception
     */
    public static function overload(Event $event)
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

        foreach ($envs as $extraKey) {
            if (array_key_exists($extraKey, $extra)) {
                $autoload = $event->getComposer()->getPackage()->getAutoload();
                if (array_key_exists('classmap', $autoload) === false) {
                    $autoload['classmap'] = array();
                }

                foreach ($extra[$extraKey] as $className => $infos) {
                    static::generateProxy(
                        $cacheDir,
                        $className,
                        $infos['original-file'],
                        $event->getIO()
                    );
                    $autoload['classmap'][$className] = $infos['overload-file'];
                }

                $event->getComposer()->getPackage()->setAutoload($autoload);
            }
        }
    }

    /**
     * @param string $path
     * @param IOInterface $io
     */
    protected function createDirectories($path, IOInterface $io)
    {
        if (is_dir($path) === false) {
            $io->write('Creating directory <info>' . $path . '</info>.', true, IOInterface::VERBOSE);

            $createdPath = null;
            foreach (explode(DIRECTORY_SEPARATOR, $path) as $directory) {
                if (is_dir($createdPath . $directory) === false) {
                    mkdir($createdPath . $directory);
                }
                $createdPath .= $directory . DIRECTORY_SEPARATOR;
            }
        }
    }

    /**
     * @param string $cacheDir
     * @param string $fullyQualifiedClassName
     * @param string $filePath
     * @param IOInterface $io
     * @return string
     */
    protected static function generateProxy($cacheDir, $fullyQualifiedClassName, $filePath, IOInterface $io)
    {
        $php = static::getPhpForDuplicatedFile($filePath, $fullyQualifiedClassName);
        $classNameParts = array_merge(array(static::NAMESPACE_PREFIX), explode('\\', $fullyQualifiedClassName));
        array_pop($classNameParts);
        $finalCacheDir = $cacheDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $classNameParts);
        static::createDirectories($finalCacheDir, $io);

        $overloadedFilePath = $finalCacheDir . DIRECTORY_SEPARATOR . basename($filePath);
        file_put_contents($overloadedFilePath, $php);

        $io->write(
            '<info>' . $filePath . '</info> is overloaded by <comment>' . $overloadedFilePath . '</comment>',
            true,
            IOInterface::VERBOSE
        );
    }

    /**
     * @param string $filePath
     * @param string $fullyQualifiedClassName
     * @return string
     * @throws \Exception
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
                } elseif ($isGlobalUse && $token[0] === T_USE) {
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
     * @param array $classFound
     * @param string $fullyQualifiedClassName
     * @param string $filePath
     * @throws \Exception
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
     * @param array $tokens
     * @param int $index
     * @return string
     * @throws \Exception
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
     * @param array $uses
     * @param array $addUses
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

    /**
     * @param array $addUses
     * @param array $phpLines
     * @param int $line
     */
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
