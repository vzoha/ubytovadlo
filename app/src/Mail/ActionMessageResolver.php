<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\MessageTemplate;
use App\Entity\ReservationAction;
use App\Enum\MessageKind;

/**
 * Řekne, jaká zpráva z akce na časové ose odejde: kterou šablonu použije a jak
 * bude vypadat po dosazení dat rezervace. Jedno místo pro odeslání i náhled —
 * co ubytovatelka vidí v náhledu, to hostovi opravdu přijde.
 */
class ActionMessageResolver
{
    public function __construct(
        private readonly GuestMessageRenderer $renderer,
        private readonly MessageTemplateProvider $templates,
    ) {
    }

    /**
     * Ad-hoc šablona z volného textu akce (vlastní zpráva). Null = použije se
     * uložená šablona daného druhu.
     */
    public function template(ReservationAction $action, MessageKind $kind): ?MessageTemplate
    {
        if ($kind !== MessageKind::CUSTOM) {
            return null;
        }
        $payload = $action->getPayload() ?? [];
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        return new MessageTemplate(MessageKind::CUSTOM, $this->templates->for(MessageKind::CUSTOM)->getSubject(), $text);
    }

    /**
     * Vyrenderovaná podoba zprávy s daty konkrétní rezervace. Null u akce, která
     * hostovi zprávu neposílá.
     *
     * @param string|null $logoSrc zdroj loga v <img src> (URL pro náhled); null = bez loga
     */
    public function render(ReservationAction $action, ?string $logoSrc = null): ?RenderedMessage
    {
        $kind = MessageKind::fromActionType($action->getType());
        if ($kind === null) {
            return null;
        }

        $template = $this->template($action, $kind);
        $reservation = $action->getReservation();

        return $template !== null
            ? $this->renderer->renderTemplate($template, $reservation, [], $logoSrc)
            : $this->renderer->render($kind, $reservation, [], $logoSrc);
    }
}
