<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concern\ChecksCsrf;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Osobní účet přihlášeného uživatele — přehled a změna hesla.
 */
class ProfileController extends AbstractController
{
    use ChecksCsrf;

    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/profil', name: 'profile_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $this->changePassword($user, $request);

            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    private function changePassword(User $user, Request $request): void
    {
        $this->assertCsrf($request, 'profile-password');

        $current = (string) $request->request->get('current_password');
        $new = (string) $request->request->get('new_password');
        $confirm = (string) $request->request->get('confirm_password');

        if (!$this->hasher->isPasswordValid($user, $current)) {
            $this->addFlash('danger', 'Současné heslo nesouhlasí.');

            return;
        }
        if (\strlen($new) < self::MIN_PASSWORD_LENGTH) {
            $this->addFlash('danger', sprintf('Nové heslo musí mít alespoň %d znaků.', self::MIN_PASSWORD_LENGTH));

            return;
        }
        if ($new !== $confirm) {
            $this->addFlash('danger', 'Nové heslo a jeho potvrzení se neshodují.');

            return;
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $this->em->flush();
        $this->addFlash('success', 'Heslo změněno.');
    }
}
