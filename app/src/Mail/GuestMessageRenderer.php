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
use League\CommonMark\ConverterInterface;
use Twig\Environment;

/**
 * Sestaví zprávu hostovi: vezme šablonu (předmět + tělo v Markdownu), dosadí
 * proměnné, převede tělo a patičku na HTML a zabalí do master layoutu s barevným
 * tématem. Logo se předává zvlášť (cid pro odeslání, URL pro náhled).
 */
final class GuestMessageRenderer
{
    public function __construct(
        private readonly MessageTemplateProvider $templates,
        private readonly MessageVariableResolver $variables,
        private readonly MailSettingsProvider $mailSettings,
        private readonly ConverterInterface $markdown,
        private readonly Environment $twig,
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

        $html = $this->twig->render('email/_layout.html.twig', [
            'theme' => $settings->theme,
            'subject' => $subject,
            'body_html' => $this->bodyToHtml($bodyMd, $settings->theme->accent),
            'footer_html' => $footerMd !== '' ? $this->toHtml($footerMd) : '',
            'logo_src' => $settings->showLogo ? $logoSrc : null,
            'sender_name' => $settings->senderName,
        ]);

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

        return $md === '' ? '' : $this->toHtml($md);
    }

    private function toHtml(string $markdown): string
    {
        return $this->markdown->convert($markdown)->getContent();
    }

    /**
     * Tělo zprávy → HTML s podporou CTA tlačítek `[[button:Popisek|odkaz]]`.
     * Tokeny vyjmeme před převodem (commonmark má html_input: escape, takže
     * by HTML tlačítka jinak zescapoval) a po převodu je vložíme zpět jako
     * table-based tlačítko v barvě akcentu.
     */
    private function bodyToHtml(string $markdown, string $accent): string
    {
        $buttons = [];
        $markdown = preg_replace_callback(
            '/\[\[button:\s*([^|\]]+?)\s*\|\s*([^\]]+?)\s*\]\]/u',
            function (array $m) use (&$buttons, $accent): string {
                $token = sprintf('xxbuttonxx%dxx', \count($buttons));
                $buttons[$token] = $this->buttonHtml($m[1], $m[2], $accent);

                return "\n\n" . $token . "\n\n";
            },
            $markdown,
        ) ?? $markdown;

        $html = $this->toHtml($markdown);

        foreach ($buttons as $token => $buttonHtml) {
            // commonmark zabalí osamocený token do <p>…</p>
            $html = str_replace('<p>' . $token . '</p>', $buttonHtml, $html);
            $html = str_replace($token, $buttonHtml, $html);
        }

        return $html;
    }

    private function buttonHtml(string $label, string $url, string $accent): string
    {
        $label = htmlspecialchars(trim($label), ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(trim($url), ENT_QUOTES, 'UTF-8');

        return '<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:8px auto 20px;">'
            . '<tr><td align="center" bgcolor="' . $accent . '" style="border-radius:8px;">'
            . '<a href="' . $url . '" style="display:inline-block; padding:12px 28px; color:#ffffff;'
            . ' font-size:15px; font-weight:600; text-decoration:none; border-radius:8px;">' . $label . '</a>'
            . '</td></tr></table>';
    }
}
