<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuestDocument;
use App\Entity\Nationality;
use App\Enum\DocumentType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulář pro online check-in jednoho hosta.
 *
 * @extends AbstractType<GuestDocument>
 */
class GuestDocumentType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'constraints' => [new NotBlank(), new Length(max: 64)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'constraints' => [new NotBlank(), new Length(max: 64)],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Datum narození',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])
            ->add('isCzechCitizen', CheckboxType::class, [
                'label' => 'Občan/občanka České republiky (nemusíte vyplňovat doklad)',
                'required' => false,
            ])
            ->add('nationality', EntityType::class, [
                'label' => 'Státní občanství',
                'class' => Nationality::class,
                'choice_label' => fn (Nationality $n) => sprintf('%s — %s', $n->getCode(), $n->getNameCs()),
                'choice_value' => 'code',
                'placeholder' => '— vyberte —',
                'required' => false,
                'mapped' => false,
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('n')->orderBy('n.nameCs', 'ASC'),
                'help' => 'U cizinců povinné. Češi nechte prázdné.',
            ])
            ->add('documentType', EnumType::class, [
                'label' => 'Typ dokladu',
                'class' => DocumentType::class,
                'choice_label' => fn (DocumentType $t) => $t->label(),
                'placeholder' => '— vyberte —',
                'required' => false,
            ])
            ->add('documentNumber', TextType::class, [
                'label' => 'Číslo dokladu',
                'required' => false,
                'constraints' => [new Length(max: 32)],
                'attr' => ['maxlength' => 32],
            ])
            ->add('visaNumber', TextType::class, [
                'label' => 'Číslo víza (pokud je)',
                'required' => false,
                'constraints' => [new Length(max: 32)],
                'attr' => ['maxlength' => 32],
            ])
            ->add('permanentResidenceAbroad', TextareaType::class, [
                'label' => 'Trvalé bydliště v zahraničí',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'Např. „Slovensko, Bratislava, Milíčova 26"',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Poznámka',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $doc = $event->getData();
            if (!$doc instanceof GuestDocument || $doc->isCzechCitizen()) {
                return;
            }
            $form = $event->getForm();

            if ($form->get('nationality')->getData() === null) {
                $form->get('nationality')->addError(new FormError('U cizinců povinné.'));
            }
            if ($doc->getDocumentType() === null) {
                $form->get('documentType')->addError(new FormError('U cizinců povinné.'));
            }
            if ($doc->getDocumentNumber() === null || $doc->getDocumentNumber() === '') {
                $form->get('documentNumber')->addError(new FormError('U cizinců povinné.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestDocument::class,
        ]);
    }
}
