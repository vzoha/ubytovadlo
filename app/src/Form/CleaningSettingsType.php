<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Enum\CleaningType;
use App\Service\Cleaning\CleaningPriceList;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * Jednotné nastavení úklidu — pro každý typ: zobrazovaný název + ceník
 * (práh hostů, cena do prahu, cena nad práh). Pole jsou pojmenovaná
 * <type>_label / <type>_threshold / <type>_small / <type>_large, mapování na
 * Setting klíče řeší controller. Data jako asociativní pole, ne entita.
 *
 * @extends AbstractType<mixed>
 */
class CleaningSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (CleaningType::cases() as $type) {
            $defaults = CleaningPriceList::defaultsFor($type);

            $builder
                ->add($type->value . '_label', TextType::class, [
                    'label' => 'Název',
                    'required' => false,
                    'help' => 'Prázdné = výchozí „' . $type->label() . '".',
                    'attr' => ['placeholder' => $type->label()],
                ])
                ->add($type->value . '_threshold', IntegerType::class, [
                    'label' => 'Práh hostů',
                    'help' => 'Do tohoto počtu hostů (včetně) platí nižší cena.',
                    'constraints' => [new GreaterThanOrEqual(0)],
                    'attr' => ['min' => 0],
                ])
                ->add($type->value . '_small', IntegerType::class, [
                    'label' => 'Cena do prahu (Kč)',
                    'help' => 'Výchozí: ' . $defaults['small'] . ' Kč.',
                    'constraints' => [new GreaterThanOrEqual(0)],
                    'attr' => ['min' => 0],
                ])
                ->add($type->value . '_large', IntegerType::class, [
                    'label' => 'Cena nad práh (Kč)',
                    'help' => 'Stejná jako „do prahu" = paušál. Výchozí: ' . $defaults['large'] . ' Kč.',
                    'constraints' => [new GreaterThanOrEqual(0)],
                    'attr' => ['min' => 0],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
