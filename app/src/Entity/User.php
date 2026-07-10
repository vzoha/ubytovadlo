<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserPermission;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private string $password;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(name: 'is_active')]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->password = '';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): self
    {
        $this->password = $hashedPassword;

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = array_values(array_unique($this->roles));

        return $roles !== [] ? $roles : [UserRole::CLEANER->value];
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /** Primární role odvozená z pole rolí (nejsilnější přítomná). */
    public function getRole(): UserRole
    {
        foreach (UserRole::PRIORITY as $role) {
            if (\in_array($role->value, $this->roles, true)) {
                return $role;
            }
        }

        return UserRole::CLEANER;
    }

    /** @return list<UserPermission> */
    public function getPermissions(): array
    {
        return array_values(array_filter(
            UserPermission::cases(),
            fn (UserPermission $p): bool => \in_array($p->value, $this->roles, true),
        ));
    }

    public function hasPermission(UserPermission $permission): bool
    {
        return \in_array($permission->value, $this->roles, true);
    }

    /**
     * Přepíše roli i doplňková práva jedním voláním — pole rolí je vždy
     * právě jedna základní role plus vybraná práva.
     *
     * @param list<UserPermission> $permissions
     */
    public function assignAccess(UserRole $role, array $permissions = []): self
    {
        $this->roles = array_values(array_unique(array_merge(
            [$role->value],
            array_map(static fn (UserPermission $p): string => $p->value, $permissions),
        )));

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
    }
}
