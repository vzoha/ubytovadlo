<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\Embeddable\BillingIdentity;
use App\Form\Concern\MapsValueObjectFields;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Firemní údaje jako jedno pole formuláře. {@see BillingIdentity} je neměnný,
 * takže mapování obstarává tenhle typ sám — z políček se skládá nová instance.
 *
 * Volající si přizpůsobí jednotlivá políčka přes `field_options`
 * (klíče companyName/ico/dic).
 *
 * @extends AbstractType<BillingIdentity>
 */
class BillingIdentityType extends AbstractType implements DataMapperInterface
{
    use MapsValueObjectFields;

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('companyName', TextType::class, $this->fieldOptions('companyName', [
                'label' => 'Firma (volitelné)',
                'required' => false,
            ], $options))
            ->add('ico', TextType::class, $this->fieldOptions('ico', [
                'label' => 'IČO',
                'required' => false,
            ], $options))
            ->add('dic', TextType::class, $this->fieldOptions('dic', [
                'label' => 'DIČ',
                'required' => false,
            ], $options))
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BillingIdentity::class,
            'empty_data' => static fn (): BillingIdentity => new BillingIdentity(),
            'field_options' => [],
            'label' => false,
        ]);
        $resolver->setAllowedTypes('field_options', 'array');
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof BillingIdentity) {
            return;
        }
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $fields['companyName']->setData($viewData->getCompanyName());
        $fields['ico']->setData($viewData->getIco());
        $fields['dic']->setData($viewData->getDic());
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $viewData = new BillingIdentity(
            self::stringOrNull($fields['companyName']->getData()),
            self::stringOrNull($fields['ico']->getData()),
            self::stringOrNull($fields['dic']->getData()),
        );
    }
}
