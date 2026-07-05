<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Connector\ConnectorManager;
use App\Credential\CredentialProvider;
use App\Email\EmailAttachment;
use App\Email\EmailDispatcher;
use App\Email\EmailMessage;
use App\Email\HtmlToTextConverter;
use App\Email\ImapClientFactory;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\Enum\EmailLogStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webklex\PHPIMAP\Message;

#[AsCommand(name: 'app:imap:poll', description: 'Poll the automation mailbox and dispatch new e-mails to parsers.')]
class ImapPollCommand extends Command
{
    public function __construct(
        private readonly EmailDispatcher $dispatcher,
        private readonly HtmlToTextConverter $htmlToText,
        private readonly CredentialProvider $credentials,
        private readonly ImapClientFactory $imapFactory,
        private readonly ConnectorManager $connectors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Process all messages, not only UNSEEN')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not mark messages as Seen, useful for re-runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->credentials->imapConfigured()) {
            $io->warning('Automatizační schránka nemá vyplněné přístupy (IMAP) — poll přeskočen.');

            return Command::SUCCESS;
        }

        // E-mailové konektory sdílejí jednu schránku — poll má smysl, jen když je
        // aspoň jeden zapnutý; jeho výsledkem se aktualizuje jejich stav zdraví.
        $activeConnectors = array_values(array_filter(
            ConnectorType::imapConnectors(),
            fn (ConnectorType $type): bool => $this->connectors->isEnabled($type),
        ));
        if ($activeConnectors === []) {
            $io->warning('Všechny e-mailové konektory jsou vypnuté — poll přeskočen.');

            return Command::SUCCESS;
        }

        try {
            $client = $this->imapFactory->connect();
        } catch (\Throwable $e) {
            $this->recordConnectors($activeConnectors, ConnectorStatus::ERROR, $e->getMessage());
            $io->error('Připojení k automatizační schránce selhalo: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $imapFolder = $this->credentials->imapFolder();
        $folder = $client->getFolderByPath($imapFolder);
        if ($folder === null) {
            $this->recordConnectors($activeConnectors, ConnectorStatus::ERROR, "IMAP složka nenalezena: {$imapFolder}");
            $io->error("IMAP folder not found: {$imapFolder}");

            return Command::FAILURE;
        }

        $query = $folder->messages();
        $query = $input->getOption('all') ? $query->all() : $query->unseen();
        $messages = $query->get();

        $io->writeln(sprintf('Found <info>%d</info> message(s) in %s', $messages->count(), $imapFolder));

        $processed = $ignored = $errors = $skipped = 0;
        $dryRun = $input->getOption('dry-run');

        foreach ($messages as $message) {
            $email = $this->toEmailMessage($message);

            // Zpráva vypnutého konektoru: necháme ji nepřečtenou a nezalogovanou,
            // ať se po zapnutí konektoru zpracuje. (Jinak by ji Seen + email_log
            // navždy „spotřebovaly" a data by se ztratila.)
            $type = $this->dispatcher->connectorType($email);
            if ($type !== null && !$this->connectors->isEnabled($type)) {
                $io->writeln(sprintf('  <fg=gray>–</> [disabled:%s] %s', $type->value, $email->subject));
                $skipped++;
                continue;
            }

            $log = $this->dispatcher->dispatch($email);

            $statusSymbol = match ($log->getStatus()) {
                EmailLogStatus::PROCESSED => '<fg=green>✓</>',
                EmailLogStatus::IGNORED => '<fg=yellow>·</>',
                EmailLogStatus::ERROR => '<fg=red>✗</>',
                default => '?',
            };
            $io->writeln(sprintf('  %s [%s] %s', $statusSymbol, $log->getStatus()->value, $email->subject));
            if ($log->getStatus() === EmailLogStatus::ERROR) {
                $io->writeln('    └─ ' . $log->getError());
                $errors++;
            } elseif ($log->getStatus() === EmailLogStatus::IGNORED) {
                $ignored++;
            } else {
                $processed++;
            }

            if (!$dryRun) {
                $message->setFlag('Seen');
            }
        }

        // Transport funguje (spojení i složka OK) — poslední aktivitu jednotlivých
        // konektorů zapsal dispatcher u zpracovaných zpráv. Chyby parsování se
        // zdraví transportu netýkají, drží je email_log.
        $this->recordConnectors($activeConnectors, ConnectorStatus::OK);

        $io->success(sprintf('Done. processed=%d ignored=%d skipped=%d errors=%d', $processed, $ignored, $skipped, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<ConnectorType> $types
     */
    private function recordConnectors(array $types, ConnectorStatus $status, ?string $error = null): void
    {
        foreach ($types as $type) {
            $this->connectors->recordRun($type, $status, $error);
        }
    }

    private function toEmailMessage(Message $message): EmailMessage
    {
        $messageId = trim((string) $message->getMessageId());
        if ($messageId === '') {
            $rawBody = $message->getRawBody();
            $messageId = sha1($rawBody !== '' ? $rawBody : (string) $message->getUid());
        }

        $from = null;
        $fromCollection = $message->getFrom();
        if ($fromCollection->count() > 0) {
            $first = $fromCollection->first();
            $from = $first->mail;
        }

        $date = $message->getDate()->first();
        $dateImmutable = $date instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($date)
            : new \DateTimeImmutable();

        $html = (string) $message->getHTMLBody();
        $textRaw = (string) $message->getTextBody();
        $text = $html !== '' ? $this->htmlToText->convert($html) : $textRaw;

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $filename = (string) $attachment->getName();
            if ($filename === '') {
                continue;
            }
            $attachments[] = new EmailAttachment(
                filename: $filename,
                contentType: (string) $attachment->getContentType(),
                content: (string) $attachment->getContent(),
            );
        }

        $subject = (string) $message->getSubject();
        if (str_contains($subject, '=?')) {
            $decoded = iconv_mime_decode($subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) {
                $subject = $decoded;
            }
        }

        return new EmailMessage(
            messageId: $messageId,
            fromAddress: $from,
            subject: $subject,
            date: $dateImmutable,
            textBody: $text,
            attachments: $attachments,
        );
    }
}
