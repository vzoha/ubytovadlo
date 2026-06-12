<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Mrz\MrzParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only harness that drives the REAL browser OCR pipeline (Canvas
 * preprocessing + tesseract.js + parseMany) over the local MRZ corpus, so the
 * production frontend path can be measured against ground truth the same way
 * app:mrz:test measures the container path. Not committed for production; gated
 * to the dev container only.
 */
#[When('dev')]
final class DevMrzBrowserTestController extends AbstractController
{
    private const CORPUS_DIR = '/app/var/mrz-corpus';

    public function __construct(private readonly MrzParser $parser)
    {
    }

    #[Route('/dev/mrz-browser-test', name: 'dev_mrz_browser_test', methods: ['GET'])]
    public function index(): Response
    {
        $gtPath = self::CORPUS_DIR . '/ground-truth.json';
        if (!is_file($gtPath)) {
            throw new NotFoundHttpException('ground-truth.json chybí');
        }
        /** @var array<string, array<string, mixed>> $gt */
        $gt = json_decode((string) file_get_contents($gtPath), true, flags: \JSON_THROW_ON_ERROR);

        return $this->render('dev/mrz_browser_test.html.twig', [
            'groundTruth' => $gt,
        ]);
    }

    #[Route('/dev/mrz-corpus-image/{file}', name: 'dev_mrz_corpus_image', methods: ['GET'], requirements: ['file' => '[a-zA-Z0-9_.-]+'])]
    public function image(string $file): BinaryFileResponse
    {
        $path = self::CORPUS_DIR . '/' . basename($file);
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        return new BinaryFileResponse($path);
    }

    #[Route('/dev/mrz-parse', name: 'dev_mrz_parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $texts = $payload['mrz_texts'] ?? [];
        if (!\is_array($texts)) {
            return new JsonResponse(['error' => 'mrz_texts musí být pole'], 400);
        }
        $texts = array_values(array_filter(
            array_map(static fn ($t) => \is_string($t) ? $t : '', $texts),
            static fn (string $t) => trim($t) !== '',
        ));
        if ($texts === []) {
            return new JsonResponse(['error' => 'prázdné'], 422);
        }

        $result = $this->parser->parseMany($texts);
        if ($result === null) {
            return new JsonResponse(['error' => 'nerozpoznáno', 'confidence' => 0], 422);
        }

        return new JsonResponse($result->toArray() + ['confidence' => $result->confidence]);
    }
}
