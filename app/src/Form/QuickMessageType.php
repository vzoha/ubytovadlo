<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuickMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Krátká zpráva pro SMS/WhatsApp — název do nabídky a tělo s proměnnými.
 *
 * @extends AbstractType<QuickMessage>
 */
class QuickMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Název',
                'constraints' => [new NotBlank(), new Length(max: 64)],
                'help' => 'Zobrazí se v nabídce u tlačítek SMS a WhatsApp.',
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Text zprávy',
                'constraints' => [new NotBlank()],
                'attr' => ['rows' => 5],
                'help' => 'Krátký text pro SMS/WhatsApp. Proměnné vložíš tlačítky níže.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuickMessage::class]);
    }
}
