<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Správa uživatelů — matice role × doplňková práva. Jen pro admina.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/nastaveni/uzivatele')]
class UserController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'user_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $this->users->findBy([], ['createdAt' => 'ASC']),
            'roles' => UserRole::cases(),
        ]);
    }

    #[Route('/novy', name: 'user_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user-create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');
        $role = UserRole::tryFrom((string) $request->request->get('role', ''));

        if ($email === '' || $role === null) {
            $this->addFlash('danger', 'Vyplň e-mail i roli.');

            return $this->redirectToRoute('user_index');
        }
        if (\strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->addFlash('danger', sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_PASSWORD_LENGTH));

            return $this->redirectToRoute('user_index');
        }
        if ($this->users->findOneBy(['email' => $email]) !== null) {
            $this->addFlash('danger', 'Uživatel s tímto e-mailem už existuje.');

            return $this->redirectToRoute('user_index');
        }

        $user = new User($email);
        $user->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        $this->addFlash('success', sprintf('Uživatel %s založen.', $email));

        return $this->redirectToRoute('user_index');
    }

    #[Route('/{id}', name: 'user_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user-update-' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $role = UserRole::tryFrom((string) $request->request->get('role', '')) ?? $user->getRole();
        $active = $request->request->getBoolean('active');

        if ($this->wouldOrphanAdmins($user, $role, $active)) {
            $this->addFlash('danger', 'Musí zůstat aspoň jeden aktivní admin.');

            return $this->redirectToRoute('user_index');
        }
        if ($this->isSelf($user) && ($role !== UserRole::ADMIN || !$active)) {
            $this->addFlash('danger', 'Vlastní admin práva ani aktivní stav si odebrat nemůžeš.');

            return $this->redirectToRoute('user_index');
        }

        $user->setRole($role);
        $user->setActive($active);
        $this->em->flush();
        $this->addFlash('success', sprintf('Uživatel %s upraven.', $user->getEmail()));

        return $this->redirectToRoute('user_index');
    }

    #[Route('/{id}/heslo', name: 'user_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetPassword(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user-password-' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $password = (string) $request->request->get('password');
        if (\strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->addFlash('danger', sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_PASSWORD_LENGTH));

            return $this->redirectToRoute('user_index');
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();
        $this->addFlash('success', sprintf('Heslo uživatele %s změněno.', $user->getEmail()));

        return $this->redirectToRoute('user_index');
    }

    #[Route('/{id}/smazat', name: 'user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user-delete-' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isSelf($user)) {
            $this->addFlash('danger', 'Vlastní účet smazat nelze.');

            return $this->redirectToRoute('user_index');
        }
        if ($this->wouldOrphanAdmins($user, null, false)) {
            $this->addFlash('danger', 'Musí zůstat aspoň jeden aktivní admin.');

            return $this->redirectToRoute('user_index');
        }

        $email = $user->getEmail();
        $this->em->remove($user);
        $this->em->flush();
        $this->addFlash('success', sprintf('Uživatel %s smazán.', $email));

        return $this->redirectToRoute('user_index');
    }

    /**
     * Vede navrhovaná změna k tomu, že by nezbyl žádný aktivní admin?
     * `$newRole === null` znamená, že se uživatel maže.
     */
    private function wouldOrphanAdmins(User $target, ?UserRole $newRole, bool $newActive): bool
    {
        $remaining = 0;
        foreach ($this->users->findAll() as $user) {
            [$role, $active] = $user->getId() === $target->getId()
                ? [$newRole, $newActive]
                : [$user->getRole(), $user->isActive()];
            if ($role === UserRole::ADMIN && $active) {
                $remaining++;
            }
        }

        return $remaining === 0;
    }

    private function isSelf(User $user): bool
    {
        return $this->getUser() instanceof User && $this->getUser()->getUserIdentifier() === $user->getUserIdentifier();
    }
}
