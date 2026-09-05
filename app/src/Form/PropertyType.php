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
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Objekt, který pronajímáte — název a adresa pro hosty. Tyhle údaje vidí host
 * ve zprávách a adresu z nich přebírá i hlášení na Ubyport; adresa dodavatele
 * na fakturách je něco jiného a nastavuje se ve Fakturaci.
 *
 * @extends AbstractType<AccommodationProfile>
 */
class PropertyType extends AbstractType
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
            ->add('nazev', TextType::class, [
                'label' => 'Název ubytování',
                'help' => 'Jak se objekt jmenuje pro hosty — objeví se ve zprávách i na stránkách check-inu.',
                'constraints' => [new NotBlank()],
            ])
            ->add('address', PropertyAddressType::class, [
                'required_fields' => ['obec', 'psc', 'okres'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AccommodationProfile::class]);
    }
}
