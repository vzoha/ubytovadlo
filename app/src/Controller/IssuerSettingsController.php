<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Form\IssuerSettingsType;
use App\Form\NumberingSettingsType;
use App\Invoice\InvoiceNumberFormat;
use App\Invoice\InvoiceSeriesConfig;
use App\Invoice\IssuerProfileProvider;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stránka „Fakturace": dodavatel + bankovní spojení a číselná řada faktur
 * (formát čísla a příští pořadové číslo). Dvě samostatné formy na jedné stránce.
 */
class IssuerSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly IssuerProfileProvider $issuerProvider,
        private readonly InvoiceNumberFormat $numberFormat,
        private readonly InvoiceSeriesConfig $series,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/dodavatel', name: 'issuer_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $issuerForm = $this->createForm(IssuerSettingsType::class, $this->issuerProvider->currentValues());
        $issuerForm->handleRequest($request);
        if ($issuerForm->isSubmitted() && $issuerForm->isValid()) {
            foreach (IssuerProfileProvider::KEYS as $field => $key) {
                $value = trim((string) $issuerForm->get($field)->getData());
                $this->settings->set($key, $value, 'Dodavatel na faktuře.');
            }
            $this->em->flush();
            $this->addFlash('success', 'Údaje dodavatele uloženy. Nové faktury je použijí; u stávajících přegeneruj PDF.');

            return $this->redirectToRoute('issuer_settings_edit');
        }

        $year = (int) (new \DateTimeImmutable('today'))->format('Y');
        $numberingForm = $this->createForm(NumberingSettingsType::class, [
            'format' => $this->numberFormat->pattern(),
            'nextNumber' => $this->series->all()[$year] ?? null,
        ], ['year' => $year]);
        $numberingForm->handleRequest($request);
        if ($numberingForm->isSubmitted()) {
            $format = trim((string) $numberingForm->get('format')->getData());
            if ($format !== '' && !InvoiceNumberFormat::isValid($format)) {
                $numberingForm->get('format')->addError(new FormError('Formát musí obsahovat rok ({RRRR} nebo {RR}) i pořadí ({NNN}); povolený je jen text a oddělovače.'));
            }
            if ($numberingForm->isValid()) {
                $this->saveNumbering($format, $numberingForm->get('nextNumber')->getData(), $year);
                $this->addFlash('success', 'Číselná řada uložena.');

                return $this->redirectToRoute('issuer_settings_edit');
            }
        }

        return $this->render('issuer_settings/edit.html.twig', [
            'form' => $issuerForm->createView(),
            'numberingForm' => $numberingForm->createView(),
            'example' => $this->numberFormat->format($year, 12),
        ]);
    }

    private function saveNumbering(string $format, mixed $nextNumber, int $year): void
    {
        $this->settings->set(InvoiceNumberFormat::KEY, $format, 'Formát čísla faktury.');

        $map = $this->series->all();
        if (is_int($nextNumber) && $nextNumber >= 1) {
            $map[$year] = $nextNumber;
        } else {
            unset($map[$year]);
        }
        ksort($map);
        $this->settings->set(InvoiceSeriesConfig::KEY, json_encode($map, JSON_THROW_ON_ERROR), 'Navázání číselné řady faktur (rok → první číslo).');

        $this->em->flush();
    }
}
