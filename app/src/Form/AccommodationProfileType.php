<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccommodationProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<AccommodationProfile>
 */
class AccommodationProfileType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('idub', TextType::class, [
                'label' => 'IDUB (12 číslic od cizinecké policie)',
                'attr' => ['maxlength' => 12, 'inputmode' => 'numeric'],
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^\d{12}$/', message: 'IDUB musí být 12 číslic.'),
                ],
            ])
            ->add('kod', TextType::class, [
                'label' => 'Kód zařízení (5 znaků, např. UBYT1)',
                'attr' => ['maxlength' => 5, 'style' => 'text-transform: uppercase'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 1, max: 5),
                ],
            ])
            ->add('nazev', TextType::class, [
                'label' => 'Název zařízení',
                'constraints' => [new NotBlank()],
            ])
            ->add('spojeni', TextType::class, [
                'label' => 'Kontaktní spojení (jméno + telefon)',
                'help' => 'Např. „Jan Novák, tel: 261 197 135"',
                'constraints' => [new NotBlank()],
            ])
            ->add('okres', TextType::class, [
                'label' => 'Okres',
                'constraints' => [new NotBlank()],
            ])
            ->add('obec', TextType::class, [
                'label' => 'Obec',
                'constraints' => [new NotBlank()],
            ])
            ->add('castObce', TextType::class, [
                'label' => 'Část obce',
                'required' => false,
            ])
            ->add('ulice', TextType::class, [
                'label' => 'Ulice',
                'required' => false,
            ])
            ->add('cp', TextType::class, [
                'label' => 'Číslo popisné',
                'required' => false,
                'attr' => ['maxlength' => 16],
            ])
            ->add('co', TextType::class, [
                'label' => 'Číslo orientační',
                'required' => false,
                'attr' => ['maxlength' => 16],
            ])
            ->add('psc', TextType::class, [
                'label' => 'PSČ',
                'attr' => ['maxlength' => 8, 'inputmode' => 'numeric'],
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^\d{3} ?\d{2}$/', message: 'PSČ musí být 5 číslic (např. 38901).'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccommodationProfile::class,
        ]);
    }
}
