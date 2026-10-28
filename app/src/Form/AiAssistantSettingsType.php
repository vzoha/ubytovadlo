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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Informace o ubytování pro AI asistenta. Pole `propertyInfo` mapuje controller
 * na Setting klíč. Data jako asociativní pole, ne entita.
 *
 * @extends AbstractType<mixed>
 */
class AiAssistantSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('propertyInfo', TextareaType::class, [
            'label' => 'Informace o ubytování',
            'required' => false,
            'help' => 'Z tohoto textu asistent odpovídá hostům. Co tu chybí, nechá v odpovědi k doplnění.',
            'constraints' => [new Length(max: 20000)],
            'attr' => [
                'rows' => 14,
                'placeholder' => "Ceník: …\nPříjezd od 15:00, odjezd do 10:00. Klíče: …\nParkování: …\nPes: …\nOkolí a tipy na výlety: …",
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
