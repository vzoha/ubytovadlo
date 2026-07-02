<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nastavení notifikací ubytovateli: adresa příjemce + režim doručení pro každý
 * typ (pole pojmenované hodnotou enumu). Mapování na Setting klíče řeší
 * controller. Data jako asociativní pole, ne entita.
 *
 * @extends AbstractType<mixed>
 */
class OwnerNotificationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresa příjemce',
            'required' => false,
            'attr' => ['placeholder' => 'ja@example.cz'],
            'help' => 'Bez vyplněné adresy se notifikace neposílají.',
        ]);

        foreach (OwnerNotificationType::cases() as $type) {
            $builder->add($type->value, EnumType::class, [
                'class' => OwnerNotificationMode::class,
                'choice_label' => static fn (OwnerNotificationMode $mode): string => $mode->label(),
                'label' => $type->label(),
                'help' => $type->description(),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
