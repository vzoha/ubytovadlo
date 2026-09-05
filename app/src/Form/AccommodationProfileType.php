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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Celý profil objektu v jednom kroku průvodce — údaje ubytování i identifikátory
 * pro Ubyport.
 *
 * @extends AbstractType<AccommodationProfile>
 */
class AccommodationProfileType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Průvodce vede jedním krokem přes obojí; v nastavení má každá část
        // vlastní stránku (Ubytování a Ubyport).
        PropertyType::addFields($builder);
        UbyportIdentifiersType::addFields($builder);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccommodationProfile::class,
        ]);
    }
}
