<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\MessageTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Editace šablony e-mailu hostovi — předmět a tělo v Markdownu s proměnnými.
 *
 * @extends AbstractType<MessageTemplate>
 */
class MessageTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Zprávu odesílat',
                'required' => false,
                'help' => 'Vypnutá zpráva se naplánuje, ale neodešle.',
            ])
            ->add('subject', TextType::class, [
                'label' => 'Předmět',
                'constraints' => [new NotBlank()],
            ])
            ->add('bodyMarkdown', TextareaType::class, [
                'label' => 'Tělo zprávy (Markdown)',
                'constraints' => [new NotBlank()],
                'attr' => ['rows' => 14, 'class' => 'font-monospace'],
                'help' => 'Tlačítko do e-mailu: [[button:Text|odkaz]], např. [[button:Dokončit check-in|{{ checkin_url }}]].',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MessageTemplate::class]);
    }
}
