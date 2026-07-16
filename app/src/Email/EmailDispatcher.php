<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

use App\Connector\ConnectorManager;
use App\Email\Handler\EmailHandler;
use App\Entity\EmailLog;
use App\Enum\ConnectorType;
use App\Repository\EmailLogRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Zpracuje příchozí e-mail: najde handler, který ho podporuje, a nechá ho e-mail
 * promítnout do domény. Sám drží jen idempotenci (podle messageId), kontrolu
 * zapnutého konektoru a evidenci jeho aktivity. Nový typ e-mailu = nový
 * `EmailHandler`, bez zásahu do dispatcheru.
 */
class EmailDispatcher
{
    /**
     * @param iterable<EmailHandler> $handlers
     */
    public function __construct(
        private readonly EmailLogRepository $emailLogs,
        private readonly ConnectorManager $connectors,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('app.email_handler')]
        private readonly iterable $handlers,
    ) {
    }

    public function dispatch(EmailMessage $email): EmailLog
    {
        $existing = $this->emailLogs->findByMessageId($email->messageId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->em->wrapInTransaction(fn () => $this->process($email));
        } catch (UniqueConstraintViolationException) {
            $this->em->clear();
            $log = $this->emailLogs->findByMessageId($email->messageId);
            if ($log !== null) {
                return $log;
            }
            throw new \RuntimeException(sprintf('EmailLog for messageId "%s" missing after unique violation', $email->messageId));
        }
    }

    private function process(EmailMessage $email): EmailLog
    {
        $log = new EmailLog($email->messageId, $email->date);
        $log->setFromAddress($email->fromAddress);
        $log->setSubject($email->subject);
        $this->em->persist($log);

        $handler = $this->handlerFor($email);
        if ($handler !== null) {
            $connectorType = $handler->connectorType();
            if (!$this->connectors->isEnabled($connectorType)) {
                $log->markIgnored(sprintf('Konektor „%s" je vypnutý', $connectorType->label()));

                return $log;
            }
            // Zpráva z tohoto zdroje dorazila → poslední aktivita konektoru (i když
            // ji nakonec ignorujeme, transport žije — podklad pro „nechodí data").
            $this->connectors->recordActivity($connectorType, $email->date);
        }

        try {
            if ($handler !== null) {
                $handler->handle($email, $log);
            } else {
                $log->markIgnored('No parser matched');
            }
        } catch (UniqueConstraintViolationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Email dispatch failed', [
                'messageId' => $email->messageId,
                'subject' => $email->subject,
                'exception' => $e,
            ]);
            $log->markError($e->getMessage());
        }

        return $log;
    }

    /**
     * Kterému konektoru zpráva patří (podle odesílatele), nebo null když žádnému.
     * Poller si tím ověří, jestli zprávu vůbec zpracovávat (vypnutý konektor přeskočí).
     */
    public function connectorType(EmailMessage $email): ?ConnectorType
    {
        return $this->handlerFor($email)?->connectorType();
    }

    private function handlerFor(EmailMessage $email): ?EmailHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($email)) {
                return $handler;
            }
        }

        return null;
    }
}
