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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nastavení připojení: přístupové údaje (IMAP, MotoPress REST, SMTP) i chování
 * MotoPressu (mapování služeb, push plateb). Údaje odpovídají CredentialProvider::FIELDS
 * (šifrují se), chování se ukládá do Setting — obojí řeší controller.
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
            ->add('motopressConsumerSecret', PasswordType::class, ['label' => 'Consumer secret', 'help' => 'Prázdné = beze změny.'] + $secret)
            ->add('motopressEnabled', CheckboxType::class, [
                'label' => 'Importovat rezervace z MotoPressu',
                'required' => false,
                'help' => 'Vypnuto = rezervace z webu se nestahují. Rezervace jde vždy přidat i ručně.',
            ])
            ->add('petServiceIds', TextType::class, [
                'label' => 'ID služeb „pes"',
                'required' => false,
                'help' => 'ID MotoPress služeb, které znamenají „host se psem". Víc oddělte čárkou.',
            ])
            ->add('babyCotServiceIds', TextType::class, [
                'label' => 'ID služeb „dětská postýlka"',
                'required' => false,
                'help' => 'ID MotoPress služeb pro dětskou postýlku. Víc oddělte čárkou.',
            ])
            ->add('pushPayments', CheckboxType::class, [
                'label' => 'Posílat potvrzené platby zpět do MotoPressu',
                'required' => false,
            ])
            ->add('smtpHost', TextType::class, ['label' => 'SMTP server', 'required' => false, 'help' => 'Prázdné = použije se MAILER_DSN z prostředí.'])
            ->add('smtpPort', IntegerType::class, ['label' => 'Port', 'required' => false, 'help' => 'Obvykle 465 (SSL) nebo 587 (TLS).'])
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
