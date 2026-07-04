<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Enum\BillingMode;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Ruční přidání rezervace — přímý host bez OTA i webového funnelu. Přebírá pole
 * hosta z {@see ReservationDetailsType} a doplňuje termín, cenu a fakturační režim.
 * Cena je vždy v Kč (přímí hosté platí v Kč).
 */
class ReservationManualType extends ReservationDetailsType
{
    public function getParent(): string
    {
        return ReservationDetailsType::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('checkIn', DateType::class, [
                'label' => 'Příjezd',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('checkOut', DateType::class, [
                'label' => 'Odjezd',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('priceTotal', TextType::class, [
                'label' => 'Cena celkem (Kč)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'např. 8500'],
            ])
            ->add('billingMode', EnumType::class, [
                'label' => 'Fakturační režim',
                'class' => BillingMode::class,
                'required' => false,
                'placeholder' => 'Zvolit později',
                'choice_label' => static fn (BillingMode $mode): string => $mode->label(),
            ])
            // Přepsání zděděného textového pole ISO kódu na výběr země (host se
            // vyplňuje ručně, ne z Booking extranetu) — čeština, časté země první.
            ->add('guestCountry', CountryType::class, [
                'label' => 'Země',
                'required' => false,
                'placeholder' => false,
                'preferred_choices' => ['CZ', 'SK', 'DE', 'AT', 'PL'],
                'choice_translation_locale' => 'cs',
            ]);
    }
}
