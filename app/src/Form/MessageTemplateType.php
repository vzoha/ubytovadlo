<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Form;

use App\Entity\MessageTemplate;
use App\Enum\SendMode;
use App\Enum\TimingAnchor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Editace zprávy hostovi — režim odesílání, časování na ose (u plánovaných druhů)
 * a text (předmět + tělo v Markdownu s proměnnými). Časování se v UI zadává jako
 * směr + počet dní vůči kotvě; do entity se skládá do znaménkového posunu.
 *
 * @extends AbstractType<MessageTemplate>
 */
class MessageTemplateType extends AbstractType
{
    private const DIRECTION_BEFORE = 'before';
    private const DIRECTION_AFTER = 'after';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', EnumType::class, [
                'class' => SendMode::class,
                'label' => 'Režim odesílání',
                'expanded' => true,
                'choice_label' => fn (SendMode $m) => $m->label(),
                'choice_attr' => fn (SendMode $m) => ['data-help' => $m->description()],
            ])
            ->add('subject', TextType::class, [
                'label' => 'Předmět',
                'constraints' => [new NotBlank()],
            ])
            ->add('bodyMarkdown', TextareaType::class, [
                'label' => 'Tělo zprávy (Markdown)',
                'constraints' => [new NotBlank()],
                'attr' => ['rows' => 14, 'class' => 'font-monospace'],
                'help' => 'Tlačítko do e-mailu: [[button:Text|odkaz]], např. [[button:Dokončit check-in|{{ checkin_url }}]].',
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
        $builder->addEventListener(FormEvents::SUBMIT, [$this, 'onSubmit']);
    }

    /** Časovací pole má jen plánovaný druh zprávy; předvyplní směr + počet dní z posunu. */
    public function onPreSetData(FormEvent $event): void
    {
        $template = $event->getData();
        $form = $event->getForm();
        if (!$template instanceof MessageTemplate || !$template->getKind()->isScheduled()) {
            return;
        }

        $offset = $template->getOffsetDays() ?? 0;

        $form
            ->add('offsetDays', IntegerType::class, [
                'mapped' => false,
                'label' => 'Počet dní',
                'data' => abs($offset),
                'constraints' => [new GreaterThanOrEqual(0)],
                'attr' => ['min' => 0, 'max' => 60],
            ])
            ->add('offsetDirection', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Před / po',
                'choices' => ['před' => self::DIRECTION_BEFORE, 'po' => self::DIRECTION_AFTER],
                'data' => $offset > 0 ? self::DIRECTION_AFTER : self::DIRECTION_BEFORE,
            ])
            ->add('anchor', EnumType::class, [
                'class' => TimingAnchor::class,
                'label' => 'Vůči',
                'choice_label' => fn (TimingAnchor $a) => ucfirst($a->label()),
                'placeholder' => false,
            ])
            ->add('sendAt', TextType::class, [
                'label' => 'V hodin',
                'required' => false,
                'attr' => ['placeholder' => '09:00'],
                'help' => 'Prázdné = v přesný čas té události (objednávky, příjezdu nebo odjezdu).',
                'constraints' => [new Regex(pattern: '/^([01]?\d|2[0-3]):[0-5]\d$/', message: 'Zadej čas ve tvaru HH:MM.')],
            ]);
    }

    /** Složí směr + počet dní do znaménkového posunu na entitě. */
    public function onSubmit(FormEvent $event): void
    {
        $template = $event->getData();
        $form = $event->getForm();
        if (!$template instanceof MessageTemplate || !$form->has('offsetDays')) {
            return;
        }

        $days = abs((int) $form->get('offsetDays')->getData());
        $direction = $form->get('offsetDirection')->getData();
        $template->setOffsetDays($direction === self::DIRECTION_AFTER ? $days : -$days);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MessageTemplate::class]);
    }
}
