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
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Ruční přidání rezervace — přímý host bez OTA i webového funnelu. Přebírá pole
 * hosta z {@see ReservationDetailsType}, termín a cenu z {@see ReservationStayType}
 * a doplňuje fakturační režim.
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
        foreach (ReservationStayType::stayFields() as $name => [$type, $fieldOptions]) {
            $builder->add($name, $type, $fieldOptions);
        }
        $builder
            ->add('billingMode', EnumType::class, [
                'label' => 'Fakturační režim',
                'class' => BillingMode::class,
                'required' => false,
                'placeholder' => 'Zvolit později',
                'choice_label' => static fn (BillingMode $mode): string => $mode->label(),
            ])
            // Země jako výběr, ne ISO kód (host se vyplňuje ručně, ne z Booking
            // extranetu) — čeština, časté země první.
            ->add('guestAddress', AddressType::class, [
                'country_type' => CountryType::class,
                'field_options' => [
                    'country' => [
                        'label' => 'Země',
                        'required' => false,
                        'placeholder' => false,
                        'preferred_choices' => ['CZ', 'SK', 'DE', 'AT', 'PL'],
                        'choice_translation_locale' => 'cs',
                    ],
                ],
            ]);
    }
}
