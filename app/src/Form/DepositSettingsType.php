<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Enum\DepositMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * Pravidla zálohy pro web klasiku a ruční rezervace: způsob výše (fixní částka /
 * procento z ceny / bez zálohy), hodnota a splatnost. Data jako pole
 * {mode, value, dueDays}; ukládání do Setting řeší controller.
 *
 * @extends AbstractType<mixed>
 */
class DepositSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', EnumType::class, [
                'class' => DepositMode::class,
                'label' => 'Způsob výše',
                'choice_label' => fn (DepositMode $m): string => $m->label(),
                'help' => '„Bez zálohy" znamená, že web klasika i ruční rezervace jdou rovnou na jednu fakturu.',
            ])
            ->add('value', TextType::class, [
                'label' => 'Výše',
                'required' => false,
                'help' => 'U fixní částky v Kč (např. 1000), u procenta v % (např. 30). Při „bez zálohy" se nepoužije.',
            ])
            ->add('dueDays', IntegerType::class, [
                'label' => 'Splatnost zálohy (dnů)',
                'help' => 'Kolik dnů od vystavení má host na uhrazení zálohy.',
                'constraints' => [new GreaterThanOrEqual(0)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
