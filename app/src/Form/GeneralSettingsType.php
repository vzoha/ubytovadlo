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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Obecné nastavení instance — název (brand) a veřejná adresa aplikace.
 * Data jako asociativní pole (brandName, baseUrl), mapování na Setting klíče
 * řeší controller přes InstanceSettings.
 *
 * @extends AbstractType<mixed>
 */
class GeneralSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('brandName', TextType::class, [
                'label' => 'Název instance',
                'help' => 'Zobrazuje se v hlavičce, titulcích a na fakturách.',
                'constraints' => [new NotBlank()],
            ])
            ->add('baseUrl', UrlType::class, [
                'label' => 'Veřejná adresa aplikace',
                'required' => false,
                'help' => 'Např. https://app.tvojedomena.cz — použije se pro odkazy v e-mailech odeslaných z cronu.',
                'constraints' => [new Url(requireTld: false)],
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'help' => 'PNG nebo JPG, doporučeně na výšku max 300 px. Objeví se v hlavičce e-mailů hostům a na fakturách.',
                'constraints' => [
                    new Image(
                        maxSize: '2M',
                        mimeTypes: ['image/png', 'image/jpeg'],
                        mimeTypesMessage: 'Nahraj obrázek ve formátu PNG nebo JPG.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
