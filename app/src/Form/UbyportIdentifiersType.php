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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Údaje, které používá jen hlášení cizinecké policii: identifikátory a název
 * z registrace ubytovatele a adresa zařízení v hlavičce hlášení. Adresa je buď
 * shodná s adresou objektu, nebo vlastní — pak se vyplní celá, ať hlavička
 * nemíchá dva zdroje.
 *
 * @extends AbstractType<AccommodationProfile>
 */
class UbyportIdentifiersType extends AbstractType
{
    public const SOURCE_PROPERTY = 'property';
    public const SOURCE_OWN = 'own';

    /** Pole, bez kterých hlášení nemá úplnou adresu zařízení. */
    private const REQUIRED_ADDRESS_FIELDS = ['okres', 'obec', 'psc'];

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        self::addFields($builder);

        $builder
            ->add('reportingName', TextType::class, [
                'label' => 'Název zařízení v hlášení',
                'help' => 'Jak je zařízení zapsané u cizinecké policie — může se lišit od názvu, který znají hosté.',
                'constraints' => [new NotBlank()],
            ])
            ->add('reportingSource', ChoiceType::class, [
                'label' => 'Adresa v hlášení',
                'mapped' => false,
                'expanded' => true,
                'choices' => [
                    'Stejná jako v Ubytování' => self::SOURCE_PROPERTY,
                    'Jiná adresa' => self::SOURCE_OWN,
                ],
            ])
            ->add('reportingAddress', PropertyAddressType::class)
            ->addEventListener(FormEvents::POST_SET_DATA, self::prefillFromProperty(...))
            ->addEventListener(FormEvents::POST_SUBMIT, self::applySource(...), 10);
    }

    /**
     * Identifikátory zvlášť, aby je průvodce mohl poskládat do jednoho kroku
     * s údaji o ubytování.
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

    /**
     * Volba i políčka se ukazují vyplněná: dokud zařízení vlastní údaje nemá,
     * nabídnou se hodnoty z Ubytování jako předloha k přepsání.
     */
    private static function prefillFromProperty(FormEvent $event): void
    {
        $profile = $event->getData();
        if (!$profile instanceof AccommodationProfile) {
            return;
        }

        $form = $event->getForm();
        if ($profile->getReportingName() === null) {
            $form->get('reportingName')->setData($profile->getNazev());
        }

        $ownAddress = $profile->hasOwnReportingAddress();
        $form->get('reportingSource')->setData($ownAddress ? self::SOURCE_OWN : self::SOURCE_PROPERTY);
        if (!$ownAddress) {
            $form->get('reportingAddress')->setData($profile->getAddress());
        }
    }

    /**
     * Volba rozhoduje, co se uloží: „stejná jako v Ubytování" vlastní adresu
     * zahodí, „jiná adresa" ji vyžaduje úplnou.
     */
    private static function applySource(FormEvent $event): void
    {
        $profile = $event->getData();
        if (!$profile instanceof AccommodationProfile) {
            return;
        }

        $form = $event->getForm();
        if ($form->get('reportingSource')->getData() !== self::SOURCE_OWN) {
            $profile->usesPropertyAddressInReport();

            return;
        }

        foreach (self::REQUIRED_ADDRESS_FIELDS as $field) {
            self::requireFilled(
                $form->get('reportingAddress')->get($field),
                'Vyplňte — hlášení musí nést úplnou adresu zařízení.',
            );
        }
    }

    /**
     * @param FormInterface<mixed> $field
     */
    private static function requireFilled(FormInterface $field, string $message): void
    {
        if (trim((string) $field->getData()) === '') {
            $field->addError(new FormError($message));
        }
    }
}
