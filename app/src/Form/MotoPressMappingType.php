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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Chování napojení na MotoPress — ID služeb (pes, dětská postýlka) a push plateb.
 * Data jako pole {petServiceIds, babyCotServiceIds, pushPayments}; ID jako čárkami
 * oddělený seznam. Mapování na Setting řeší controller přes MotoPressSettings.
 *
 * @extends AbstractType<mixed>
 */
class MotoPressMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('petServiceIds', TextType::class, [
                'label' => 'ID služeb „pes"',
                'required' => false,
                'help' => 'ID MotoPress služeb, které znamenají „host se psem". Víc oddělte čárkou.',
            ])
            ->add('babyCotServiceIds', TextType::class, [
                'label' => 'ID služeb „dětská postýlka"',
                'required' => false,
                'help' => 'ID MotoPress služeb pro dětskou postýlku. Víc oddělte čárkou.',
            ])
            ->add('pushPayments', CheckboxType::class, [
                'label' => 'Posílat potvrzené platby zpět do MotoPressu',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
