<?php

namespace X3Group\Bitrix24\Application\Install;

use Bitrix24\SDK\Services\ServiceBuilder;
use Psr\Log\LoggerInterface;
use X3Group\Bitrix24\Contracts\Bitrix24Installer;

class AppSetupRunner
{
    /** @var callable(class-string): object */
    private $resolver;

    public function __construct(private LoggerInterface $logger, ?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static fn(string $class) => app($class);
    }

    /** @param class-string[] $installerClasses */
    public function run(ServiceBuilder $b24, array $installerClasses): void
    {
        foreach ($installerClasses as $class) {
            $installer = ($this->resolver)($class);
            if (!$installer instanceof Bitrix24Installer) {
                throw new \InvalidArgumentException(sprintf('Installer %s must implement %s', is_object($installer) ? $installer::class : (string)$class, Bitrix24Installer::class));
            }
            try {
                $installer->install($b24);
            } catch (\Throwable $e) {
                $this->logger->error('b24 installer failed', ['installer' => $class, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                throw $e;
            }
        }
    }
}
