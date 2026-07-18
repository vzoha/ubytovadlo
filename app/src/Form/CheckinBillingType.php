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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fakturační údaje objednatele vyplňované hostem v rámci online check-inu
 * (podmnožina {@see ReservationDetailsType} — bez provozních polí, které řeší
 * majitelka). Firma/IČO/DIČ jsou volitelné; IČO umí předvyplnit firmu z ARES.
 * Texty se překládají v doméně `checkin` podle jazyka zvoleného hostem.
 *
 * @extends AbstractType<Reservation>
 */
class CheckinBillingType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // attr placeholdery Symfony sám nepřekládá, takže je přeložíme ručně;
        // labely/help si v doméně `checkin` přeloží framework.
        $companyPlaceholder = $this->translator->trans('form.company_placeholder', [], 'checkin');
        $countryPlaceholder = $this->translator->trans('form.country_placeholder', [], 'checkin');

        $builder
            ->add('guestName', TextType::class, [
                'label' => 'form.guest_name',
                'required' => true,
            ])
            ->add('guestBilling', BillingIdentityType::class, [
                'field_options' => [
                    'companyName' => ['label' => 'form.company', 'attr' => ['placeholder' => $companyPlaceholder]],
                    'ico' => ['label' => 'form.ico', 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off']],
                    'dic' => ['label' => 'form.dic'],
                ],
            ])
            ->add('guestAddress', AddressType::class, [
                'field_options' => [
                    'street' => ['label' => 'form.street', 'required' => true],
                    'zip' => ['label' => 'form.zip', 'required' => true],
                    'city' => ['label' => 'form.city', 'required' => true],
                    'country' => ['label' => 'form.country', 'required' => false, 'attr' => ['maxlength' => 2, 'placeholder' => $countryPlaceholder]],
                ],
            ])
            ->add('guestContact', GuestContactType::class, [
                'fields' => ['email'],
                'field_options' => [
                    'email' => ['label' => 'form.email'],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
            'translation_domain' => 'checkin',
        ]);
    }
}
