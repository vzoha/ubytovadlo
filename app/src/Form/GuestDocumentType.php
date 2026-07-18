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
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formulář pro online check-in jednoho hosta. Texty se překládají v doméně
 * `checkin` podle jazyka zvoleného hostem; seznam zemí se zobrazuje v jeho
 * jazyce (česky pro `cs`, jinak anglicky).
 *
 * @extends AbstractType<GuestDocument>
 */
class GuestDocumentType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $czech = $this->localeSwitcher->getLocale() === 'cs';

        $builder
            ->add('lastName', TextType::class, [
                'label' => 'form.last_name',
                'constraints' => [new NotBlank(), new Length(max: 64)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'form.first_name',
                'constraints' => [new NotBlank(), new Length(max: 64)],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'form.birth_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])
            ->add('isCzechCitizen', CheckboxType::class, [
                'label' => 'form.is_czech',
                'required' => false,
            ])
            ->add('nationality', EntityType::class, [
                'label' => 'form.nationality',
                'class' => Nationality::class,
                'choice_label' => fn (Nationality $n) => sprintf('%s — %s', $n->getCode(), $czech ? $n->getNameCs() : $n->getNameEn()),
                'choice_value' => 'code',
                'placeholder' => 'form.nationality_placeholder',
                'required' => false,
                'mapped' => false,
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('n')->orderBy($czech ? 'n.nameCs' : 'n.nameEn', 'ASC'),
                'help' => 'form.nationality_help',
            ])
            ->add('documentType', EnumType::class, [
                'label' => 'form.document_type',
                'class' => DocumentType::class,
                'choice_label' => fn (DocumentType $t) => $this->translator->trans('doc_type.' . $t->value, [], 'checkin'),
                'placeholder' => 'form.document_type_placeholder',
                'required' => false,
            ])
            ->add('documentNumber', TextType::class, [
                'label' => 'form.document_number',
                'required' => false,
                'constraints' => [new Length(max: 32)],
                'attr' => ['maxlength' => 32],
            ])
            ->add('residenceAddress', TextareaType::class, [
                'label' => 'form.residence',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'form.residence_help',
            ])
            ->add('visaNumber', TextType::class, [
                'label' => 'form.visa_number',
                'required' => false,
                'constraints' => [new Length(max: 32)],
                'attr' => ['maxlength' => 32],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'form.note',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);

        $registerCzechGuests = (bool) $options['registerCzechGuests'];

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($registerCzechGuests): void {
            $doc = $event->getData();
            if (!$doc instanceof GuestDocument) {
                return;
            }
            $form = $event->getForm();
            $isForeigner = !$doc->isCzechCitizen();
            $required = $this->translator->trans('form.field_required', [], 'checkin');

            // Doklad a adresu vyžadujeme do evidenční knihy (§ 3g) u každého hosta;
            // u českého jen když ho vůbec evidujeme.
            if ($isForeigner || $registerCzechGuests) {
                if ($doc->getDocumentType() === null) {
                    $form->get('documentType')->addError(new FormError($required));
                }
                if ($doc->getDocumentNumber() === null || $doc->getDocumentNumber() === '') {
                    $form->get('documentNumber')->addError(new FormError($required));
                }
                if ($doc->getResidenceAddress() === null || trim($doc->getResidenceAddress()) === '') {
                    $form->get('residenceAddress')->addError(new FormError($required));
                }
            }

            // Občanství je navíc jen u cizince (podklad pro Ubyport).
            if ($isForeigner && $form->get('nationality')->getData() === null) {
                $form->get('nationality')->addError(new FormError($this->translator->trans('form.required_for_foreigners', [], 'checkin')));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestDocument::class,
            'translation_domain' => 'checkin',
            'registerCzechGuests' => true,
        ]);
        $resolver->setAllowedTypes('registerCzechGuests', 'bool');
    }
}
