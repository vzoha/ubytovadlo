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
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Editace zprávy hostovi — režim odesílání, časování na ose (u plánovaných druhů)
 * a text (předmět + tělo v Markdownu s proměnnými).
 *
 * Časování má dvě podoby: „v přesný čas kotvy" (v okamžik objednávky/příjezdu/
 * odjezdu — posun 0, bez hodiny) nebo „posun o dní vůči kotvě v konkrétní hodinu".
 * Do entity se skládá na posun (znaménkový) + hodinu (`null` = přesný čas).
 *
 * @extends AbstractType<MessageTemplate>
 */
class MessageTemplateType extends AbstractType
{
    private const DIRECTION_BEFORE = 'before';
    private const DIRECTION_AFTER = 'after';
    private const TIMING_EXACT = 'exact';
    private const TIMING_OFFSET = 'offset';

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

    /** Časovací pole má jen plánovaný druh zprávy; předvyplní volbu z uloženého času. */
    public function onPreSetData(FormEvent $event): void
    {
        $template = $event->getData();
        $form = $event->getForm();
        if (!$template instanceof MessageTemplate || !$template->getKind()->isScheduled()) {
            return;
        }

        $offset = $template->getOffsetDays() ?? 0;
        $exact = $template->getSendAt() === null;

        $form
            ->add('anchor', EnumType::class, [
                'class' => TimingAnchor::class,
                'label' => 'Vůči',
                'choice_label' => fn (TimingAnchor $a) => ucfirst($a->label()),
                'placeholder' => false,
            ])
            ->add('timingMode', ChoiceType::class, [
                'mapped' => false,
                'label' => false,
                'expanded' => true,
                'choices' => ['V přesný čas příjezdu' => self::TIMING_EXACT, 'Pár dní před nebo po' => self::TIMING_OFFSET],
                'data' => $exact ? self::TIMING_EXACT : self::TIMING_OFFSET,
            ])
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
            ->add('sendAt', TextType::class, [
                'mapped' => false,
                'label' => 'V hodin',
                'required' => false,
                'data' => $template->getSendAt(),
                'attr' => ['placeholder' => '09:00'],
                'constraints' => [new Regex(pattern: '/^([01]?\d|2[0-3]):[0-5]\d$/', message: 'Zadej čas ve tvaru HH:MM.')],
            ]);
    }

    /** Podle zvolené varianty složí posun a hodinu na entitě. */
    public function onSubmit(FormEvent $event): void
    {
        $template = $event->getData();
        $form = $event->getForm();
        if (!$template instanceof MessageTemplate || !$form->has('timingMode')) {
            return;
        }

        // V přesný čas kotvy: v den události (posun 0), bez pevné hodiny.
        if ($form->get('timingMode')->getData() === self::TIMING_EXACT) {
            $template->setOffsetDays(0);
            $template->setSendAt(null);

            return;
        }

        $days = abs((int) $form->get('offsetDays')->getData());
        $template->setOffsetDays($form->get('offsetDirection')->getData() === self::DIRECTION_AFTER ? $days : -$days);

        $sendAt = trim((string) $form->get('sendAt')->getData());
        if ($sendAt === '') {
            $form->get('sendAt')->addError(new FormError('Zadej hodinu odeslání.'));

            return;
        }
        $template->setSendAt($sendAt);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MessageTemplate::class]);
    }
}
