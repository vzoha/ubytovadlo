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
 * Přístupové údaje připojení (IMAP, MotoPress). Pole odpovídají
 * CredentialProvider::FIELDS, mapování + šifrování řeší controller.
 * Tajemství (hesla, klíče) jsou write-only: prázdné = beze změny.
 *
 * @extends AbstractType<mixed>
 */
class ConnectionSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $secret = ['required' => false, 'always_empty' => true, 'mapped' => true];

        $builder
            ->add('imapHost', TextType::class, ['label' => 'IMAP server', 'required' => false, 'help' => 'Např. mail.example.com'])
            ->add('imapPort', IntegerType::class, ['label' => 'Port', 'required' => false, 'help' => 'Obvykle 993 (SSL).'])
            ->add('imapEncryption', ChoiceType::class, [
                'label' => 'Šifrování',
                'required' => false,
                'choices' => ['SSL' => 'ssl', 'TLS' => 'tls', 'Žádné' => ''],
            ])
            ->add('imapUsername', TextType::class, ['label' => 'Uživatel (e-mail)', 'required' => false])
            ->add('imapPassword', PasswordType::class, ['label' => 'Heslo', 'help' => 'Prázdné = beze změny.'] + $secret)
            ->add('imapFolder', TextType::class, ['label' => 'Složka', 'required' => false, 'help' => 'Obvykle INBOX.'])
            ->add('motopressBaseUrl', TextType::class, ['label' => 'MotoPress URL', 'required' => false, 'help' => 'Adresa webu s pluginem, např. https://example.com'])
            ->add('motopressConsumerKey', PasswordType::class, ['label' => 'Consumer key', 'help' => 'Prázdné = beze změny.'] + $secret)
            ->add('motopressConsumerSecret', PasswordType::class, ['label' => 'Consumer secret', 'help' => 'Prázdné = beze změny.'] + $secret);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
