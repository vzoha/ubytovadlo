<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Přístupy k poště: příchozí schránka (IMAP), ze které se čtou zprávy portálů
 * a banky, a odchozí server (SMTP), kterým aplikace posílá. Názvy polí odpovídají
 * {@see \App\Credential\CredentialProvider::FIELDS}, ukládá je controller šifrovaně.
 * Tajemství jsou write-only: prázdné pole = beze změny.
 *
 * @extends AbstractType<mixed>
 */
class MailboxSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $secret = ['required' => false, 'always_empty' => true, 'mapped' => true];

        $builder
            ->add('imapHost', TextType::class, ['label' => 'IMAP server', 'required' => false, 'help' => 'Např. mail.example.com'])
            ->add('imapPort', IntegerType::class, ['label' => 'Port', 'required' => false, 'help' => 'Obvykle 993 (SSL).', 'attr' => ['min' => 1, 'max' => 65535]])
            ->add('imapEncryption', ChoiceType::class, [
                'label' => 'Šifrování',
                'required' => false,
                'choices' => ['SSL' => 'ssl', 'TLS' => 'tls', 'Žádné' => ''],
            ])
            ->add('imapUsername', TextType::class, ['label' => 'Uživatel (e-mail)', 'required' => false])
            ->add('imapPassword', PasswordType::class, ['label' => 'Heslo', 'help' => 'Prázdné = beze změny.'] + $secret)
            ->add('imapFolder', TextType::class, ['label' => 'Složka', 'required' => false, 'help' => 'Obvykle INBOX.'])
            ->add('smtpHost', TextType::class, ['label' => 'SMTP server', 'required' => false, 'help' => 'Adresa odchozího serveru, např. mail.vasedomena.cz.'])
            ->add('smtpPort', IntegerType::class, ['label' => 'Port', 'required' => false, 'help' => 'Obvykle 465 (SSL) nebo 587 (TLS).', 'attr' => ['min' => 1, 'max' => 65535]])
            ->add('smtpEncryption', ChoiceType::class, [
                'label' => 'Šifrování',
                'required' => false,
                'choices' => ['SSL' => 'ssl', 'TLS' => 'tls', 'Žádné' => ''],
            ])
            ->add('smtpUsername', TextType::class, ['label' => 'Uživatel', 'required' => false])
            ->add('smtpPassword', PasswordType::class, ['label' => 'Heslo', 'help' => 'Prázdné = beze změny.'] + $secret);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
