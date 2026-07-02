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
use App\Entity\Reservation;
use App\Enum\MessageKind;

/**
 * Sestaví zprávu hostovi: vezme šablonu (předmět + tělo v Markdownu), dosadí
 * proměnné a zabalí do master layoutu (EmailLayoutRenderer) s barevným tématem.
 * Logo se předává zvlášť (cid pro odeslání, URL pro náhled).
 */
final class GuestMessageRenderer
{
    public function __construct(
        private readonly MessageTemplateProvider $templates,
        private readonly MessageVariableResolver $variables,
        private readonly MailSettingsProvider $mailSettings,
        private readonly EmailLayoutRenderer $layout,
    ) {
    }

    /**
     * @param array<string, string> $context dodatečné proměnné (např. invoice_number)
     * @param string|null           $logoSrc zdroj loga v <img src> (cid:logo / URL); null = bez loga
     */
    public function render(MessageKind $kind, Reservation $reservation, array $context = [], ?string $logoSrc = null): RenderedMessage
    {
        return $this->renderTemplate($this->templates->for($kind), $reservation, $context, $logoSrc);
    }

    /**
     * Vyrenderuje konkrétní (i neuloženou) šablonu — pro náhled rozeditovaných změn.
     *
     * @param array<string, string> $context
     */
    public function renderTemplate(MessageTemplate $template, Reservation $reservation, array $context = [], ?string $logoSrc = null): RenderedMessage
    {
        $settings = $this->mailSettings->current();

        $subject = $this->variables->render($template->getSubject(), $reservation, $context);
        $bodyMd = $this->variables->render($template->getBodyMarkdown(), $reservation, $context);
        $footerMd = $this->variables->render($settings->footer, $reservation, $context);

        $html = $this->layout->render($subject, $bodyMd, $footerMd, $logoSrc);

        $text = trim($bodyMd . ($footerMd !== '' ? "\n\n—\n" . $footerMd : ''));

        return new RenderedMessage($subject, $html, $text);
    }

    /**
     * Náhled patičky (Markdown + proměnné → HTML) — shodnou cestou jako reálný
     * e-mail, takže náhled v nastavení odpovídá výsledku.
     *
     * @param array<string, string> $context
     */
    public function renderFooterPreview(string $footerMarkdown, Reservation $reservation, array $context = []): string
    {
        $md = $this->variables->render($footerMarkdown, $reservation, $context);

        return $md === '' ? '' : $this->layout->toHtml($md);
    }
}
