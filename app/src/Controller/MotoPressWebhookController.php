<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Connector\ConnectorManager;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\MotoPress\MotoPressApiException;
use App\MotoPress\MotoPressSync;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Push z WordPressu: po potvrzení rezervace MotoPress hook zavolá tuhle URL,
 * takže se web rezervace naimportuje hned (na sdíleném hostingu, kde cron neběží
 * častěji než á 15 min). WP posílá jen ID rezervace, detail si dotáhne sync sám
 * z REST API. Chrání jen token v URL, ne login — volá to stroj WordPressu.
 * Cronový poll zůstává jako záchranná síť, kdyby push nedorazil.
 */
class MotoPressWebhookController extends AbstractController
{
    public function __construct(
        private readonly ConnectorManager $connectors,
        private readonly MotoPressSync $sync,
    ) {
    }

    #[Route('/webhook/motopress/{token}', name: 'motopress_webhook', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function receive(string $token, Request $request): JsonResponse
    {
        if (!$this->connectors->webhookTokenMatches(ConnectorType::MOTOPRESS, $token)) {
            throw $this->createNotFoundException();
        }

        // Vypnutý/nenakonfigurovaný konektor: 200 „skipped", ať WP push neloguje
        // jako chybu. Import stejně zajistí (nebo vynechá) cronový poll.
        if (!$this->connectors->isEnabled(ConnectorType::MOTOPRESS)) {
            return $this->skipped('connector_disabled');
        }
        if (!$this->connectors->isConfigured(ConnectorType::MOTOPRESS)) {
            return $this->skipped('not_configured');
        }

        $body = $request->getContent();
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return new JsonResponse(['status' => 'error', 'reason' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
            }
            $bookingId = is_array($decoded) ? (int) ($decoded['booking_id'] ?? 0) : 0;
        } else {
            $bookingId = $request->request->getInt('booking_id');
        }
        if ($bookingId <= 0) {
            return new JsonResponse(['status' => 'error', 'reason' => 'missing_booking_id'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->sync->syncById($bookingId);
        } catch (UniqueConstraintViolationException) {
            // Cron poll (nebo souběžné ťuknutí) rezervaci vytvořil ve stejný okamžik —
            // je tedy naimportovaná. Idempotentní výsledek, ne chyba. (EntityManager je
            // po téhle výjimce zavřený, proto stav konektoru nezapisujeme.)
            return new JsonResponse(['status' => 'ok', 'reason' => 'already_imported']);
        } catch (MotoPressApiException $e) {
            $this->connectors->recordRun(ConnectorType::MOTOPRESS, ConnectorStatus::ERROR, $e->getMessage());

            return new JsonResponse(['status' => 'error', 'reason' => 'sync_failed'], Response::HTTP_BAD_GATEWAY);
        }

        $this->connectors->recordActivity(ConnectorType::MOTOPRESS);
        $this->connectors->recordRun(ConnectorType::MOTOPRESS, ConnectorStatus::OK);

        return new JsonResponse([
            'status' => 'ok',
            'created' => $result->created,
            'updated' => $result->updated,
            'unchanged' => $result->unchanged,
            'skipped' => $result->skipped,
        ]);
    }

    private function skipped(string $reason): JsonResponse
    {
        return new JsonResponse(['status' => 'skipped', 'reason' => $reason]);
    }
}
