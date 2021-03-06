<?php

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Zentlix to newer
 * versions in the future. If you wish to customize Zentlix for your
 * needs please refer to https://docs.zentlix.io for more information.
 */

declare(strict_types=1);

namespace Zentlix\UserBundle\Application\Command\User;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zentlix\MainBundle\Domain\Attribute\Service\Attributes;
use Zentlix\MainBundle\Infrastructure\Share\Bus\CommandHandlerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zentlix\UserBundle\Domain\Admin\Repository\SettingRepository;
use Zentlix\UserBundle\Domain\Group\Specification\ExistGroupByCodeSpecification;
use Zentlix\UserBundle\Domain\Group\Repository\GroupRepository;
use Zentlix\UserBundle\Domain\User\Entity\User;
use Zentlix\UserBundle\Domain\User\Event\BeforeUpdate;
use Zentlix\UserBundle\Domain\User\Event\AfterUpdate;
use Zentlix\UserBundle\Domain\User\Specification\UniqueEmailSpecification;

class UpdateHandler implements CommandHandlerInterface
{
    private UniqueEmailSpecification $uniqueEmailSpecification;
    private EntityManagerInterface $entityManager;
    private EventDispatcherInterface $eventDispatcher;
    private UserPasswordEncoderInterface $passwordEncoder;
    private GroupRepository $groupRepository;
    private ExistGroupByCodeSpecification $existGroupByCodeSpecification;
    private SettingRepository $settingRepository;
    private Attributes $attributes;

    public function __construct(UniqueEmailSpecification $uniqueEmailSpecification,
                                ExistGroupByCodeSpecification $existGroupByCodeSpecification,
                                EntityManagerInterface $entityManager,
                                EventDispatcherInterface $eventDispatcher,
                                UserPasswordEncoderInterface $passwordEncoder,
                                GroupRepository $groupRepository,
                                SettingRepository $settingRepository,
                                Attributes $attributes)
    {
        $this->uniqueEmailSpecification = $uniqueEmailSpecification;
        $this->entityManager = $entityManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->passwordEncoder = $passwordEncoder;
        $this->groupRepository = $groupRepository;
        $this->existGroupByCodeSpecification = $existGroupByCodeSpecification;
        $this->settingRepository = $settingRepository;
        $this->attributes = $attributes;
    }

    public function __invoke(UpdateCommand $command): void
    {
        $user = $command->getEntity();

        $this->validate($command, $user);

        $command->groups = $this->groupRepository->findByCode($command->groups);

        $this->eventDispatcher->dispatch(new BeforeUpdate($command));

        $this->attributes->saveValues($user, $command->attributes);

        $user->update($command);
        if($command->plain_password) {
            $user->setPassword($this->passwordEncoder->encodePassword($user, $command->plain_password));
        }
        $this->deleteAdminSettings($user);

        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new AfterUpdate($user, $command));
    }

    private function deleteAdminSettings(User $user): void
    {
        if($user->isAdminRole() === false) {
            $adminSetting = $this->settingRepository->findByUserId($user->getId());

            if(is_null($adminSetting) === false) {
                $this->entityManager->remove($adminSetting);
            }
        }
    }

    private function validate(UpdateCommand $command, User $user): void
    {
        if(!$user->getEmail()->isEqual($command->getEmailObject())) {
            $this->uniqueEmailSpecification->isUnique($command->getEmailObject());
        }
        foreach ($command->groups as $group) {
            $this->existGroupByCodeSpecification->isExist($group);
        }
    }
}