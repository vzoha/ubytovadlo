<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\Reservation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fakturační údaje objednatele vyplňované hostem v rámci online check-inu
 * (podmnožina {@see ReservationDetailsType} — bez provozních polí, které řeší
 * majitelka). Firma/IČO/DIČ jsou volitelné; IČO umí předvyplnit firmu z ARES.
 *
 * @extends AbstractType<Reservation>
 */
class CheckinBillingType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('guestName', TextType::class, [
                'label' => 'Jméno a příjmení (nebo kontaktní osoba)',
                'required' => true,
            ])
            ->add('guestCompanyName', TextType::class, [
                'label' => 'Firma (volitelné)',
                'required' => false,
                'attr' => ['id' => 'billing-company', 'placeholder' => 'Necháte-li prázdné, faktura je na fyzickou osobu'],
            ])
            ->add('guestIco', TextType::class, [
                'label' => 'IČO',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'id' => 'billing-ico'],
            ])
            ->add('guestDic', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
                'attr' => ['id' => 'billing-dic'],
            ])
            ->add('guestStreet', TextType::class, [
                'label' => 'Ulice a č. p.',
                'required' => true,
                'attr' => ['id' => 'billing-street'],
            ])
            ->add('guestZip', TextType::class, [
                'label' => 'PSČ',
                'required' => true,
                'attr' => ['id' => 'billing-zip'],
            ])
            ->add('guestCity', TextType::class, [
                'label' => 'Město',
                'required' => true,
                'attr' => ['id' => 'billing-city'],
            ])
            ->add('guestCountry', TextType::class, [
                'label' => 'Země (ISO kód)',
                'required' => false,
                'attr' => ['id' => 'billing-country', 'maxlength' => 2, 'placeholder' => 'CZ'],
            ])
            ->add('guestEmail', EmailType::class, [
                'label' => 'E-mail (kam poslat fakturu)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
        ]);
    }
}
