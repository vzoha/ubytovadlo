<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Checkin\CheckinAccess;
use App\Checkin\CheckinProgressRecorder;
use App\Enum\DocumentReadSource;
use App\Mrz\MrzParser;
use App\Mrz\MrzResult;
use App\Repository\NationalityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Čtení strojově čitelné zóny dokladu pro předvyplnění hosta v online check-inu.
 * OCR běží v prohlížeči, sem chodí jen přečtený text. Autorizace tokenem jako
 * v CheckinController.
 */
class CheckinMrzController extends AbstractController
{
    public function __construct(
        private readonly CheckinAccess $access,
        private readonly MrzParser $mrzParser,
        private readonly NationalityRepository $nationalities,
        private readonly TranslatorInterface $translator,
        private readonly CheckinProgressRecorder $progress,
    ) {
    }

    #[Route('/checkin/{token}/mrz', name: 'checkin_mrz', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function parseMrz(string $token, Request $request): JsonResponse
    {
        $reservation = $this->access->reservationForToken($token);
        if ($reservation === null) {
            throw new NotFoundHttpException();
        }

        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $texts = self::mrzTexts($payload);
        if ($texts === []) {
            return new JsonResponse(['error' => $this->translator->trans('error.mrz_missing', [], 'checkin')], 400);
        }

        // The browser OCRs the photo several ways (preprocessing variants) and
        // sends all reads; we merge them by per-field majority vote. A single
        // `mrz` text (manual entry / legacy) is still accepted.
        $result = \is_array($payload['mrz_texts'] ?? null)
            ? $this->mrzParser->parseMany($texts)
            : $this->mrzParser->parse($texts[0]);
        $this->progress->documentRead($reservation, DocumentReadSource::fromInput($payload['source'] ?? null), $result);

        if ($result === null) {
            return new JsonResponse(['error' => $this->translator->trans('error.mrz_unrecognized', [], 'checkin')], 422);
        }

        return new JsonResponse($this->resultData($result, $request));
    }

    /**
     * Neprázdné texty z požadavku: pole `mrz_texts`, jinak jediný `mrz`.
     *
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private static function mrzTexts(array $payload): array
    {
        $texts = $payload['mrz_texts'] ?? null;
        $texts = \is_array($texts) ? $texts : [$payload['mrz'] ?? ''];

        return array_values(array_filter(
            array_map(static fn ($t) => \is_string($t) ? $t : '', $texts),
            static fn (string $t) => trim($t) !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function resultData(MrzResult $result, Request $request): array
    {
        $data = $result->toArray();
        // Confidence lets the browser gate its locate-then-recrop pass: a
        // checksum-solid read is accepted, otherwise it falls back to the
        // whole-image passes before prefilling.
        $data['confidence'] = $result->confidence;

        $nationality = $this->nationalities->find($result->nationalityCode);
        $data['nationalityFound'] = $nationality !== null;
        $nationalityName = $nationality !== null
            ? ($request->getLocale() === 'cs' ? $nationality->getNameCs() : $nationality->getNameEn())
            : null;
        $data['nationalityLabel'] = $nationality
            ? sprintf('%s — %s', $nationality->getCode(), $nationalityName)
            : null;

        return $data;
    }
}
