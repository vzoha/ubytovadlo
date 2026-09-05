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
 * Údaje, které používá jen hlášení cizinecké policii — identifikátory z registrace
 * a kontakt na ubytovatele v hlavičce hlášení. Název a adresa objektu patří
 * k ubytování, protože je čtou i zprávy hostům.
 *
 * @extends AbstractType<AccommodationProfile>
 */
class UbyportIdentifiersType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        self::addFields($builder);
    }

    /**
     * Pole zvlášť, aby je průvodce mohl poskládat do jednoho kroku.
     *
     * @param FormBuilderInterface<AccommodationProfile> $builder
     */
    public static function addFields(FormBuilderInterface $builder): void
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
            ->add('spojeni', TextType::class, [
                'label' => 'Kontakt na ubytovatele',
                'help' => 'Jméno a telefon, na který se v hlášení obrátí cizinecká policie. Např. „Jan Novák, tel: 261 197 135".',
                'constraints' => [new NotBlank()],
            ])
            ->add('kod', TextType::class, [
                'label' => 'Kód zařízení (5 znaků, např. UBYT1)',
                'attr' => ['maxlength' => 5, 'style' => 'text-transform: uppercase'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 1, max: 5),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AccommodationProfile::class]);
    }
}
