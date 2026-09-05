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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nastavení kanálu Booking.com — zatím jen ID ubytování pro odkaz do extranetu.
 *
 * @extends AbstractType<mixed>
 */
class BookingChannelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('bookingHotelId', TextType::class, [
            'label' => 'ID ubytování na Booking.com',
            'required' => false,
            'help' => 'Číslo z adresy extranetu (hotel_id). Doplní se samo z e-mailu o nové rezervaci, vlepit jde i celá adresa.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
