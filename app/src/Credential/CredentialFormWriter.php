<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Credential;

use App\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Uloží přístupové údaje z formuláře — jen ta pole, která formulář opravdu má,
 * takže stejný zápis slouží schránce, MotoPressu i dalším kanálům. Tajemství
 * s prázdným polem zůstávají beze změny.
 */
final class CredentialFormWriter
{
    public function __construct(
        private readonly CredentialRepository $credentials,
        private readonly CredentialCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isReady(): bool
    {
        return $this->cipher->isReady();
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return bool zda se zapisovalo (bez klíče se přístupy uložit nedají)
     */
    public function save(FormInterface $form): bool
    {
        if (!$this->cipher->isReady()) {
            return false;
        }

        foreach (CredentialProvider::FIELDS as $field => [$key, $isSecret]) {
            if (!$form->has($field)) {
                continue;
            }
            $value = trim((string) $form->get($field)->getData());
            if ($isSecret && $value === '') {
                continue;
            }
            $this->credentials->setEncrypted($key, $value);
        }
        $this->em->flush();

        return true;
    }
}
