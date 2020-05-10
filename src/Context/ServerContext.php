<?php

/*
 * This file is part of the Drift Server
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

declare(strict_types=1);

namespace Drift\Server\Context;

use Drift\Server\Adapter\SymfonyKernelAdapter;
use Drift\Server\Adapter\DriftKernelAdapter;
use Drift\Server\Adapter\KernelAdapter;
use Drift\HttpKernel\AsyncKernel;
use Exception;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class ServerContext.
 */
final class ServerContext
{
    private $environment;
    private $silent;
    private $staticFolder;
    private $debug;
    private $printHeader;
    private $adapter;
    private $host;
    private $port;
    private $exchanges;

    /**
     * Build by Input.
     *
     * @param InputInterface $input
     *
     * @return ServerContext
     *
     * @throws Exception Invalid kernel adapter
     */
    public static function buildByInput(InputInterface $input): ServerContext
    {
        $serverContext = new self();
        $serverContext->environment = $input->getOption('dev')
            ? 'dev'
            : $input->getOption('env');
        $serverContext->silent = $input->getOption('quiet');
        $serverContext->debug = $input->getOption('debug');
        $serverContext->printHeader = !$input->getOption('no-header');

        $adapterName = $input->getOption('adapter');
        $adapters = [
            'drift' => DriftKernelAdapter::class,
            'symfony' => SymfonyKernelAdapter::class,
        ];

        if ($adapterName === null) {
            $foundAdapterClass = false;
            $foundExistingClasses = [];
            foreach ($adapters as $adapterName => $adapterClass) {
                $kernelClass = call_user_func([$adapterClass, 'getKernelClass']);
                if (class_exists($kernelClass)) {
                    $foundExistingClasses[] = $kernelClass;
                    if (is_a($kernelClass, AsyncKernel::class, true)) {
                        $foundAdapterClass = true;
                        break;
                    }
                }
            }

            if (!$foundAdapterClass) {
                if (empty($foundExistingClasses)) {
                    fwrite(STDERR, sprintf('No supported kernel found. Please specify your own kernel adapter by implementing %s'.PHP_EOL, KernelAdapter::class));
                } else {
                    fwrite(STDERR, sprintf('One of your kernel classes %s MUST extend %s'.PHP_EOL, implode(', ', $foundExistingClasses), AsyncKernel::class));
                }
                exit(1);
            }
        }

        $adapter = $adapters[$adapterName] ?? $adapterName;

        if (!is_a($adapter, KernelAdapter::class, true)) {
            fwrite(STDERR, sprintf('You must define an existing kernel adapter, or by an alias or by a namespace. This class MUST implement %s'.PHP_EOL, KernelAdapter::class));
            exit(1);
        }

        $serverContext->adapter = $adapter;

        $staticFolder = $input->getOption('static-folder', '');
        $staticFolder = $input->getOption('no-static-folder') ? null : $staticFolder;
        if (!is_null($staticFolder)) {
            $staticFolder = empty($staticFolder)
                ? $adapter::getStaticFolder()
                : $staticFolder;
        }

        if (is_string($staticFolder) && !empty($staticFolder)) {
            $staticFolder = '/'.trim($staticFolder, '/').'/';
        }

        $serverContext->staticFolder = $staticFolder;

        $path = $input->getArgument('path');
        $serverArgs = explode(':', $path, 2);
        if (2 !== count($serverArgs)) {
            throw new Exception('The path should have a host:port format - 0.0.0.0:80');
        }

        list($host, $port) = $serverArgs;
        $serverContext->host = $host;
        $serverContext->port = \intval($port);
        $serverContext->exchanges = self::buildQueueArray($input);

        return $serverContext;
    }

    /**
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * @return bool
     */
    public function isSilent(): bool
    {
        return $this->silent;
    }

    /**
     * @return string|null
     */
    public function getStaticFolder(): ? string
    {
        return empty($this->staticFolder)
            ? null
            : $this->staticFolder;
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @return bool
     */
    public function printHeader(): bool
    {
        return $this->printHeader;
    }

    /**
     * @return string
     */
    public function getAdapter(): string
    {
        return $this->adapter;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return array
     */
    public function getExchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @return array
     */
    public function getPlainExchanges(): array
    {
        $array = [];
        foreach ($this->exchanges as $exchange => $queue) {
            $array[] = trim("$exchange:$queue", ':');
        }

        return $array;
    }

    /**
     * @return bool
     */
    public function hasExchanges(): bool
    {
        return !empty($this->exchanges);
    }

    /**
     * Build queue architecture from array of strings.
     *
     * @param InputInterface $input
     *
     * @return array
     */
    private static function buildQueueArray(InputInterface $input): array
    {
        if (!$input->hasOption('exchange')) {
            return [];
        }

        $exchanges = [];
        foreach ($input->getOption('exchange') as $exchange) {
            $exchangeParts = explode(':', $exchange, 2);
            $exchanges[$exchangeParts[0]] = $exchangeParts[1] ?? '';
        }

        return $exchanges;
    }
}
