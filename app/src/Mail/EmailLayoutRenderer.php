<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use League\CommonMark\ConverterInterface;
use Twig\Environment;

/**
 * Zabalí tělo v Markdownu do master layoutu e-mailu s barevným tématem instance.
 * Sdílené jádro pro zprávy hostům i notifikace ubytovateli — jedno místo pro
 * převod Markdownu, CTA tlačítka a HTML kostru.
 */
final class EmailLayoutRenderer
{
    public function __construct(
        private readonly MailSettingsProvider $mailSettings,
        private readonly ConverterInterface $markdown,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param string|null $footerMarkdown patička (Markdown); null/'' = bez patičky
     * @param string|null $logoSrc        zdroj loga v <img src> (cid:logo / URL); null = bez loga
     */
    public function render(string $subject, string $bodyMarkdown, ?string $footerMarkdown = null, ?string $logoSrc = null): string
    {
        $settings = $this->mailSettings->current();

        return $this->twig->render('email/_layout.html.twig', [
            'theme' => $settings->theme,
            'subject' => $subject,
            'body_html' => $this->bodyToHtml($bodyMarkdown, $settings->theme->accent),
            'footer_html' => $footerMarkdown !== null && $footerMarkdown !== '' ? $this->toHtml($footerMarkdown) : '',
            'logo_src' => $settings->showLogo ? $logoSrc : null,
            'sender_name' => $settings->senderName,
        ]);
    }

    public function toHtml(string $markdown): string
    {
        return $this->markdown->convert($markdown)->getContent();
    }

    /**
     * Tělo pro textovou (text/plain) část e-mailu: CTA tokeny `[[button:Popisek|url]]`
     * převede na čitelné „Popisek: url" (v prostém textu tlačítko nedává smysl).
     */
    public function bodyToText(string $markdown): string
    {
        return preg_replace(
            '/\[\[button:\s*([^|\]]+?)\s*\|\s*([^\]]+?)\s*\]\]/u',
            '$1: $2',
            $markdown,
        ) ?? $markdown;
    }

    /**
     * Tělo → HTML s podporou CTA tlačítek `[[button:Popisek|odkaz]]`.
     * Tokeny vyjmeme před převodem (commonmark má html_input: escape, takže
     * by HTML tlačítka jinak zescapoval) a po převodu je vložíme zpět jako
     * table-based tlačítko v barvě akcentu.
     */
    public function bodyToHtml(string $markdown, string $accent): string
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
