<?php

declare(strict_types=1);

namespace App\Email;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Loads an EmailMessage from a raw RFC822 .eml file or string.
 * Used in tests and debug; production IMAP path constructs EmailMessage
 * directly from webklex/php-imap.
 */
class EmlReader
{
    private MailMimeParser $parser;

    public function __construct(private readonly HtmlToTextConverter $htmlToText = new HtmlToTextConverter())
    {
        $this->parser = new MailMimeParser();
    }

    public function fromFile(string $path): EmailMessage
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read $path");
        }

        return $this->fromString($raw);
    }

    public function fromString(string $raw): EmailMessage
    {
        $message = $this->parser->parse($raw, false);

        $messageId = trim((string) $message->getHeaderValue(HeaderConsts::MESSAGE_ID, ''));
        if ($messageId === '') {
            $messageId = sha1($raw);
        }

        $from = null;
        $fromHeader = $message->getHeader(HeaderConsts::FROM);
        if ($fromHeader instanceof AddressHeader) {
            $first = $fromHeader->getAddresses()[0] ?? null;
            $from = $first?->getEmail();
        }

        $subject = (string) $message->getHeaderValue(HeaderConsts::SUBJECT, '');

        $dateRaw = (string) $message->getHeaderValue(HeaderConsts::DATE, '');
        $date = $dateRaw !== '' ? new \DateTimeImmutable($dateRaw) : new \DateTimeImmutable();

        $html = $message->getHtmlContent();
        if ($html !== null && trim($html) !== '') {
            $text = $this->htmlToText->convert($html);
        } else {
            $text = $message->getTextContent() ?? '';
        }

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $filename = $part->getFilename();
            if ($filename === null || $filename === '') {
                continue;
            }
            $content = $part->getContent();
            if ($content === null) {
                continue;
            }
            $attachments[] = new EmailAttachment(
                filename: $filename,
                contentType: (string) $part->getContentType(),
                content: $content,
            );
        }

        return new EmailMessage(
            messageId: $messageId,
            fromAddress: $from,
            subject: $subject,
            date: $date,
            textBody: $text,
            attachments: $attachments,
        );
    }
}
