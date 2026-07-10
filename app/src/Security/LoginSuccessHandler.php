<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Po přihlášení pokračuje na původně žádanou stránku (byl-li uživatel na login
 * přesměrován odjinud), jinak na první stránku, kterou jeho role vidí:
 * uklízečka na úklid, ostatní na seznam rezervací.
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    private const FIREWALL = 'main';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null) {
            $target = $this->getTargetPath($session, self::FIREWALL);
            if (\is_string($target) && $target !== '') {
                $this->removeTargetPath($session, self::FIREWALL);

                return new RedirectResponse($target);
            }
        }

        $route = $this->authorization->isGranted('ROLE_USER') ? 'reservation_list' : 'cleaning_index';

        return new RedirectResponse($this->urls->generate($route));
    }
}
