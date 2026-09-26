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
use App\Formatting\Money;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Termín a cena rezervace, které drží Ubytovadlo — termín jen u přímé rezervace,
 * cenu u všech kromě OTA. Pole sdílí formulář ruční rezervace
 * ({@see ReservationManualType}). Cena je vždy v Kč.
 *
 * @extends AbstractType<Reservation>
 */
class ReservationStayType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::stayFields() as $name => [$type, $fieldOptions]) {
            if ($options['with_dates'] || $name === 'priceTotal') {
                $builder->add($name, $type, $fieldOptions);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Bez dat jen cena — termín přebírá rezervace ze zdroje.
        $resolver->setDefaults(['data_class' => Reservation::class, 'with_dates' => true]);
        $resolver->setAllowedTypes('with_dates', 'bool');
    }

    /**
     * Definice polí termínu a ceny: název => [typ, volby].
     *
     * @return array<string, array{class-string<FormTypeInterface<mixed>>, array<string, mixed>}>
     */
    public static function stayFields(): array
    {
        return [
            'checkIn' => [DateType::class, [
                'label' => 'Příjezd',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
            ]],
            'checkOut' => [DateType::class, [
                'label' => 'Odjezd',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'constraints' => [
                    new Assert\Callback(static function (?\DateTimeImmutable $checkOut, ExecutionContextInterface $context): void {
                        $root = $context->getRoot();
                        $checkIn = $root instanceof FormInterface && $root->has('checkIn') ? $root->get('checkIn')->getData() : null;
                        if ($checkOut !== null && $checkIn instanceof \DateTimeImmutable && $checkOut <= $checkIn) {
                            $context->buildViolation('Odjezd musí být po příjezdu.')->addViolation();
                        }
                    }),
                ],
            ]],
            'priceTotal' => [TextType::class, [
                'label' => 'Cena celkem (Kč)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'např. 8500'],
                'constraints' => [
                    new Assert\Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        if ($value !== null && trim($value) !== '' && Money::parse($value) === null) {
                            $context->buildViolation('Cenu zadejte číslem, například 8500 nebo 8 500,50.')->addViolation();
                        }
                    }),
                ],
            ]],
        ];
    }
}
