<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Demo\DemoPortfolio;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Transfer\DataTransfer;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthSecrets;
use Patrimeter\Security\TwoFactor\BackupCodes;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Kernel of the preparation script of the end-to-end tests (tests/e2e/seed.php): the application's services, with
 * those this script uses made public. Never used by the application.
 */
final class SeedKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    private const array SERVICES = [
        AccountRepository::class, AccountTypeRepository::class, AuthSecrets::class, BackupCodes::class, BudgetRepository::class,
        Calendar::class, Connection::class, DataKeys::class, DataTransfer::class, DemoPortfolio::class, FamilyPortfolio::class, HouseholdBudget::class,
        OwnershipShareRepository::class,
        PasswordHasherFactoryInterface::class, StatementRepository::class, UserRepository::class, Vault::class,
    ];

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        foreach (self::SERVICES as $id) {
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            } else {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}
