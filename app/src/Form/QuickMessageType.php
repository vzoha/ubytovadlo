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
use App\Enum\MessageKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
                'help' => 'Zobrazí se v nabídce u SMS, WhatsAppu i zprávy do chatu.',
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Text zprávy',
                'constraints' => [new NotBlank()],
                'attr' => ['rows' => 5],
                'help' => 'Krátký text bez formátování. Proměnné vložíš tlačítky níže.',
            ])
            ->add('kind', EnumType::class, [
                'class' => MessageKind::class,
                'choices' => MessageKind::quickMessageKinds(),
                'choice_label' => static fn (MessageKind $kind): string => $kind->label(),
                'label' => 'Zastupuje automatickou zprávu',
                'required' => false,
                'placeholder' => '— žádnou —',
                'help' => 'Odeslání přes WhatsApp nebo SMS vyřídí naplánovanou automatickou zprávu stejného druhu, takže hostovi nepřijde ještě e-mailem.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuickMessage::class]);
    }
}
