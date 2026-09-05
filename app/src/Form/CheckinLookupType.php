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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vstup do online check-inu bez tokenu — kód rezervace a příjmení hosta.
 * Texty se překládají v doméně `checkin` podle jazyka zvoleného hostem.
 *
 * @extends AbstractType<array<string, string>>
 */
class CheckinLookupType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'lookup.code',
                'help' => 'lookup.code_help',
                'attr' => [
                    'autocomplete' => 'off',
                    'autocapitalize' => 'characters',
                    'placeholder' => $this->translator->trans('lookup.code_placeholder', [], 'checkin'),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'lookup.last_name',
                'attr' => ['autocomplete' => 'family-name'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'checkin',
            'data_class' => null,
        ]);
    }
}
