<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Enum\TaxProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Dodavatel na faktuře — fakturační identita provozovatele. Pole odpovídají
 * IssuerProfileProvider::KEYS, mapování na Setting klíče řeší controller.
 * Data jako asociativní pole, ne entita.
 *
 * @extends AbstractType<mixed>
 */
class IssuerSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Jméno / název dodavatele',
                'constraints' => [new NotBlank()],
            ])
            ->add('street', TextType::class, ['label' => 'Ulice a č. p.', 'required' => false])
            ->add('city', TextType::class, ['label' => 'Město', 'required' => false])
            ->add('zip', TextType::class, ['label' => 'PSČ', 'required' => false, 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'postal-code']])
            ->add('country', TextType::class, ['label' => 'Země', 'required' => false])
            ->add('ico', TextType::class, ['label' => 'IČO', 'required' => false, 'attr' => ['inputmode' => 'numeric']])
            ->add('dic', TextType::class, ['label' => 'DIČ', 'required' => false, 'help' => 'Uvádí se na faktuře u identifikované osoby i plátce DPH.'])
            ->add('taxProfile', EnumType::class, [
                'label' => 'Daňový profil',
                'class' => TaxProfile::class,
                'choice_label' => fn (TaxProfile $p): string => $p->label(),
                'help' => 'Určuje DPH na fakturách hostům a odvod z provizí OTA.',
            ])
            ->add('phone', TelType::class, ['label' => 'Telefon', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false, 'attr' => ['autocomplete' => 'email']])
            ->add('web', UrlType::class, ['label' => 'Web', 'required' => false, 'default_protocol' => null])
            ->add('bankAccount', TextType::class, ['label' => 'Číslo účtu', 'required' => false, 'help' => 'Pro platbu převodem na faktuře.'])
            ->add('bankAccountIban', TextType::class, ['label' => 'IBAN', 'required' => false, 'help' => 'Pro QR Platbu (SPAYD).']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
