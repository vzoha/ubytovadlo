<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Mail\MailThemes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Nastavení odchozích e-mailů hostům — odesílatel, patička, vzhled. Pole
 * odpovídají MailSettingsProvider, mapování na Setting klíče řeší controller.
 *
 * @extends AbstractType<mixed>
 */
class MailSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('senderName', TextType::class, [
                'label' => 'Jméno odesílatele',
                'constraints' => [new NotBlank()],
            ])
            ->add('senderEmail', EmailType::class, [
                'label' => 'E-mail odesílatele',
                'constraints' => [new NotBlank(), new Email()],
                'help' => 'Musí odpovídat schránce nakonfigurované v MAILER_DSN.',
            ])
            ->add('replyTo', EmailType::class, [
                'label' => 'Odpovědět na (Reply-To)',
                'required' => false,
                'constraints' => [new Email()],
                'help' => 'Kam dorazí odpovědi hosta, pokud se liší od odesílatele.',
            ])
            ->add('footer', TextareaType::class, [
                'label' => 'Patička',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Společná patička všech zpráv (Markdown, lze použít proměnné).',
            ])
            ->add('showLogo', CheckboxType::class, [
                'label' => 'Zobrazit logo v záhlaví',
                'required' => false,
                'help' => 'Logo nahraješ v Obecném nastavení.',
            ])
            // theme/colorPrimary/colorAccent se v UI renderují vlastním markupem
            // (swatche + color-picker), takže label/help z form theme se nezobrazí.
            ->add('theme', ChoiceType::class, [
                'choices' => MailThemes::choices(),
            ])
            ->add('colorPrimary', TextType::class, [
                'required' => false,
            ])
            ->add('colorAccent', TextType::class, [
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
