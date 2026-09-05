<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\Embeddable\PropertyAddress;
use App\Form\Concern\MapsValueObjectFields;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Adresa objektu v členění pro Ubyport jako jedno pole formuláře.
 * {@see PropertyAddress} je neměnný, takže mapování obstarává tenhle typ sám.
 *
 * Volající řekne přes `required_fields`, bez kterých políček adresa nedává
 * smysl (klíče okres, obec, castObce, ulice, cp, co, psc); zbytek zůstane
 * nepovinný.
 *
 * @extends AbstractType<PropertyAddress>
 */
class PropertyAddressType extends AbstractType implements DataMapperInterface
{
    use MapsValueObjectFields;

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('obec', TextType::class, $this->options('obec', [
                'label' => 'Obec',
            ], $options))
            ->add('castObce', TextType::class, $this->options('castObce', [
                'label' => 'Část obce',
                'help' => 'Na vesnici bez ulic nese adresu právě část obce.',
            ], $options))
            ->add('ulice', TextType::class, $this->options('ulice', [
                'label' => 'Ulice',
            ], $options))
            ->add('cp', TextType::class, $this->options('cp', [
                'label' => 'Číslo popisné',
                'attr' => ['maxlength' => 16],
            ], $options))
            ->add('co', TextType::class, $this->options('co', [
                'label' => 'Číslo orientační',
                'attr' => ['maxlength' => 16],
            ], $options))
            ->add('psc', TextType::class, $this->options('psc', [
                'label' => 'PSČ',
                'attr' => ['maxlength' => 8, 'inputmode' => 'numeric'],
                'constraints' => [new Regex(
                    pattern: '/^\d{3} ?\d{2}$/',
                    message: 'PSČ musí být 5 číslic (např. 38901).',
                )],
            ], $options))
            ->add('okres', TextType::class, $this->options('okres', [
                'label' => 'Okres',
            ], $options))
            ->setDataMapper($this);
    }

    /**
     * Políčko je povinné, jen když si ho volající vyžádal — teprve tehdy k němu
     * patří `NotBlank` i `required` v prohlížeči.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(string $field, array $defaults, array $options): array
    {
        /** @var string[] $required */
        $required = $options['required_fields'];
        $isRequired = \in_array($field, $required, true);

        /** @var list<Constraint> $constraints */
        $constraints = $defaults['constraints'] ?? [];
        if ($isRequired) {
            array_unshift($constraints, new NotBlank());
        }

        return array_replace($defaults, ['required' => $isRequired, 'constraints' => $constraints]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PropertyAddress::class,
            'empty_data' => static fn (): PropertyAddress => PropertyAddress::empty(),
            'required_fields' => [],
            'label' => false,
        ]);
        $resolver->setAllowedTypes('required_fields', 'string[]');
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof PropertyAddress) {
            return;
        }
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $fields['okres']->setData($viewData->getOkres());
        $fields['obec']->setData($viewData->getObec());
        $fields['castObce']->setData($viewData->getCastObce());
        $fields['ulice']->setData($viewData->getUlice());
        $fields['cp']->setData($viewData->getCp());
        $fields['co']->setData($viewData->getCo());
        $fields['psc']->setData($viewData->getPsc());
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $viewData = new PropertyAddress(
            self::stringOrNull($fields['okres']->getData()),
            self::stringOrNull($fields['obec']->getData()),
            self::stringOrNull($fields['castObce']->getData()),
            self::stringOrNull($fields['ulice']->getData()),
            self::stringOrNull($fields['cp']->getData()),
            self::stringOrNull($fields['co']->getData()),
            self::stringOrNull($fields['psc']->getData()),
        );
    }
}
