<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Profit\ReservationProfitCalculator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * Nastavení poplatků — sazba rekreačního poplatku (Kč / dospělý / noc).
 * Pole `recreationFeePerAdultNight` mapuje controller na Setting klíč.
 * Data jako asociativní pole, ne entita.
 *
 * @extends AbstractType<mixed>
 */
class FeesSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('recreationFeePerAdultNight', IntegerType::class, [
            'label' => 'Rekreační poplatek (Kč / dospělý / noc)',
            'help' => 'Sazba obce za pobyt dospělé osoby a noc. Děti jsou osvobozené. Výchozí: '
                . ReservationProfitCalculator::RECREATION_FEE_DEFAULT . ' Kč.',
            'constraints' => [new GreaterThanOrEqual(0)],
            'attr' => ['min' => 0],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
