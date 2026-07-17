<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\Embeddable\GuestContact;
use App\Form\Concern\MapsValueObjectFields;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Kontakt na hosta jako jedno pole formuláře. {@see GuestContact} je neměnný,
 * takže mapování obstarává tenhle typ sám — z políček se skládá nová instance.
 *
 * Volající si přizpůsobí jednotlivá políčka přes `field_options` (klíče
 * email/phone) a přes `fields` zvolí, která se vůbec zobrazí — nezobrazené
 * pole si drží dosavadní hodnotu.
 *
 * @extends AbstractType<GuestContact>
 */
class GuestContactType extends AbstractType implements DataMapperInterface
{
    use MapsValueObjectFields;

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $fields */
        $fields = $options['fields'];
        if (in_array('email', $fields, true)) {
            $builder->add('email', EmailType::class, $this->fieldOptions('email', [
                'label' => 'E-mail hosta (kam poslat fakturu)',
                'required' => false,
            ], $options));
        }
        if (in_array('phone', $fields, true)) {
            $builder->add('phone', TelType::class, $this->fieldOptions('phone', [
                'label' => 'Telefon',
                'required' => false,
            ], $options));
        }
        $builder->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestContact::class,
            'empty_data' => static fn (): GuestContact => new GuestContact(),
            'field_options' => [],
            'fields' => ['email', 'phone'],
            'label' => false,
        ]);
        $resolver->setAllowedTypes('field_options', 'array');
        $resolver->setAllowedTypes('fields', 'array');
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof GuestContact) {
            return;
        }
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        if (isset($fields['email'])) {
            $fields['email']->setData($viewData->getEmail());
        }
        if (isset($fields['phone'])) {
            $fields['phone']->setData($viewData->getPhone());
        }
    }

    /**
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var array<string, FormInterface<mixed>> $fields */
        $fields = iterator_to_array($forms);
        $contact = $viewData instanceof GuestContact ? $viewData : new GuestContact();
        if (isset($fields['email'])) {
            $contact = $contact->withEmail(self::stringOrNull($fields['email']->getData()));
        }
        if (isset($fields['phone'])) {
            $contact = $contact->withPhone(self::stringOrNull($fields['phone']->getData()));
        }
        $viewData = $contact;
    }
}
