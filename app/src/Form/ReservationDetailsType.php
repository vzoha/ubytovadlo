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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Reservation>
 */
class ReservationDetailsType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('guestName', TextType::class, [
                'label' => 'Jméno hosta',
                'required' => true,
            ])
            ->add('guestContact', GuestContactType::class)
            ->add('guestAddress', AddressType::class)
            ->add('guestBilling', BillingIdentityType::class)
            ->add('guestsAdult', IntegerType::class, [
                'label' => 'Dospělých',
                'required' => true,
                'attr' => ['min' => 0],
            ])
            ->add('guestsChild', IntegerType::class, [
                'label' => 'Dětí',
                'required' => true,
                'attr' => ['min' => 0],
            ])
            ->add('hasPet', CheckboxType::class, [
                'label' => 'Host se psem',
                'required' => false,
            ])
            ->add('petsNote', TextType::class, [
                'label' => 'Plemeno / poznámka ke psovi',
                'required' => false,
            ])
            ->add('needsBabyCot', CheckboxType::class, [
                'label' => 'Připravit dětskou postýlku',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Poznámka',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('acquisitionSource', TextType::class, [
                'label' => 'Odkud nás zná (Booking, Airbnb, Web, Facebook, E-chalupy, Návrat, …)',
                'required' => false,
                'attr' => [
                    'list' => 'acquisition-sources',
                    'autocomplete' => 'off',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
        ]);
    }
}
