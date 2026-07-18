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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Číselná řada faktur — formát čísla a příští pořadové číslo pro aktuální rok.
 * Data jako pole {format, nextNumber}; validaci formátu (tokeny) řeší controller
 * přes InvoiceNumberFormat::isValid. Rok pro popisek se předává option `year`.
 *
 * @extends AbstractType<mixed>
 */
class NumberingSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $year = $options['year'];

        $builder
            ->add('format', TextType::class, [
                'label' => 'Formát čísla faktury',
                'constraints' => [new NotBlank()],
                'help' => 'Proměnné: {RRRR} rok (nebo {RR}), {NNN} pořadí (počet N = počet cifer). '
                    . 'Můžeš přidat pevný text a oddělovače. Např. {RRRR}{NNN} → ' . $year . '012, '
                    . 'FA-{RRRR}-{NNN} → FA-' . $year . '-012.',
            ])
            ->add('nextNumber', IntegerType::class, [
                'label' => 'Příští pořadové číslo (rok ' . $year . ')',
                'required' => false,
                'help' => 'Nastav, když navazuješ na dosavadní číslování. Prázdné = pokračuje se automaticky.',
                'constraints' => [
                    new GreaterThanOrEqual(1),
                    new LessThanOrEqual(999),
                ],
                'attr' => ['min' => 1, 'max' => 999],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'year' => 0]);
        $resolver->setAllowedTypes('year', 'int');
    }
}
