<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\Embeddable\Address;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Poštovní adresa jako jedno pole formuláře. {@see Address} je neměnný, takže
 * mapování obstarává tenhle typ sám — z políček se skládá nová instance.
 *
 * Volající si přizpůsobí jednotlivá políčka přes `field_options` (klíče
 * street/zip/city/country) a typ země přes `country_type` (výchozí je ISO kód
 * jako text, hodí se i {@see CountryType} jako výběr).
 *
 * @extends AbstractType<Address>
 */
class AddressType extends AbstractType implements DataMapperInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('street', TextType::class, $this->fieldOptions('street', [
                'label' => 'Ulice a č. p.',
                'required' => false,
            ], $options))
            ->add('zip', TextType::class, $this->fieldOptions('zip', [
                'label' => 'PSČ',
                'required' => false,
            ], $options))
            ->add('city', TextType::class, $this->fieldOptions('city', [
                'label' => 'Město',
                'required' => false,
            ], $options))
            ->add('country', $options['country_type'], $this->fieldOptions('country', [
                'label' => 'Země (ISO kód, např. CZ, DE, SK)',
                'required' => false,
                'attr' => ['maxlength' => 2, 'placeholder' => 'CZ'],
            ], $options))
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Address::class,
            'empty_data' => static fn (): Address => new Address(),
            'country_type' => TextType::class,
            'field_options' => [],
            'label' => false,
        ]);
        $resolver->setAllowedTypes('country_type', 'string');
        $resolver->setAllowedTypes('field_options', 'array');
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof Address) {
            return;
        }
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $fields['street']->setData($viewData->getStreet());
        $fields['zip']->setData($viewData->getZip());
        $fields['city']->setData($viewData->getCity());
        $fields['country']->setData($viewData->getCountry());
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $viewData = new Address(
            self::stringOrNull($fields['street']->getData()),
            self::stringOrNull($fields['city']->getData()),
            self::stringOrNull($fields['zip']->getData()),
            self::stringOrNull($fields['country']->getData()),
        );
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fieldOptions(string $field, array $defaults, array $options): array
    {
        /** @var array<string, array<string, mixed>> $overrides */
        $overrides = $options['field_options'];

        return array_replace($defaults, $overrides[$field] ?? []);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
