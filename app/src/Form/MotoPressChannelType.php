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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Vlastní web s pluginem MotoPress: přístup k jeho REST API, mapování služeb
 * na příznaky rezervace a posílání potvrzených plateb zpět na web.
 *
 * @extends AbstractType<mixed>
 */
class MotoPressChannelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $secret = ['required' => false, 'always_empty' => true, 'mapped' => true];

        $builder
            ->add('motopressBaseUrl', UrlType::class, ['label' => 'MotoPress URL', 'required' => false, 'default_protocol' => null, 'help' => 'Adresa webu s pluginem, např. https://example.com'])
            ->add('motopressConsumerKey', PasswordType::class, ['label' => 'Consumer key', 'help' => 'Prázdné = beze změny.'] + $secret)
            ->add('motopressConsumerSecret', PasswordType::class, ['label' => 'Consumer secret', 'help' => 'Prázdné = beze změny.'] + $secret)
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
