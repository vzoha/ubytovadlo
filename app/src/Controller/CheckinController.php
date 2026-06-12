<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ares\AresClient;
use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Form\CheckinBillingType;
use App\Form\GuestDocumentType;
use App\Mrz\MrzParser;
use App\Repository\GuestDocumentRepository;
use App\Repository\NationalityRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Veřejné stránky pro online check-in. Authorizace = unikátní token v URL
 * (256 bit entropie, vygenerován ReservationCheckinTokenListener při vzniku
 * rezervace). Bez tokenu => 404, špatný token => 404 — neprozrazujeme stav.
 */
class CheckinController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly GuestDocumentRepository $documents,
        private readonly NationalityRepository $nationalities,
        private readonly EntityManagerInterface $em,
        private readonly MrzParser $mrzParser,
        private readonly AresClient $ares,
    ) {
    }

    #[Route('/checkin/{token}', name: 'checkin_index', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function index(string $token): Response
    {
        $reservation = $this->resolveReservation($token);
        $guests = $this->documents->findByReservation($reservation);

        return $this->render('checkin/index.html.twig', [
            'reservation' => $reservation,
            'guests' => $guests,
            'expectedGuests' => $this->expectedGuestCount($reservation),
            'isCompleted' => $reservation->getCheckinCompletedAt() !== null,
            'hasBilling' => $this->hasBillingAddress($reservation),
        ]);
    }

    #[Route('/checkin/{token}/fakturace', name: 'checkin_billing', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function billing(string $token, Request $request): Response
    {
        $reservation = $this->resolveReservation($token);
        $this->guardEditable($reservation);

        if ($reservation->getGuestCountry() === null || $reservation->getGuestCountry() === '') {
            $reservation->setGuestCountry('CZ');
        }

        $form = $this->createForm(CheckinBillingType::class, $reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Fakturační údaje uloženy.');

            return $this->redirectToRoute('checkin_index', ['token' => $token]);
        }

        return $this->render('checkin/billing.html.twig', [
            'form' => $form->createView(),
            'token' => $token,
            'reservation' => $reservation,
        ]);
    }

    #[Route('/checkin/{token}/ares', name: 'checkin_ares', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function aresLookup(string $token, Request $request): JsonResponse
    {
        $this->resolveReservation($token);

        $company = $this->ares->lookup((string) $request->query->get('ico', ''));
        if ($company === null) {
            return new JsonResponse(['found' => false], 404);
        }

        return new JsonResponse(['found' => true] + $company->toArray());
    }

    #[Route('/checkin/{token}/host/novy', name: 'checkin_host_new', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function newHost(string $token, Request $request): Response
    {
        $reservation = $this->resolveReservation($token);
        $this->guardEditable($reservation);

        $doc = new GuestDocument($reservation, '', '', new \DateTimeImmutable('1990-01-01'));

        return $this->handleForm($request, $doc, $token, isNew: true);
    }

    #[Route('/checkin/{token}/host/{id}', name: 'checkin_host_edit', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}', 'id' => '\d+'])]
    public function editHost(string $token, int $id, Request $request): Response
    {
        $reservation = $this->resolveReservation($token);
        $this->guardEditable($reservation);

        $doc = $this->resolveDocument($reservation, $id);

        return $this->handleForm($request, $doc, $token, isNew: false);
    }

    #[Route('/checkin/{token}/host/{id}/smazat', name: 'checkin_host_delete', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}', 'id' => '\d+'])]
    public function deleteHost(string $token, int $id, Request $request): Response
    {
        $reservation = $this->resolveReservation($token);
        $this->guardEditable($reservation);

        if (!$this->isCsrfTokenValid('checkin_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Neplatný formulář, zkuste to znovu.');

            return $this->redirectToRoute('checkin_index', ['token' => $token]);
        }

        $doc = $this->resolveDocument($reservation, $id);
        $this->em->remove($doc);
        $this->em->flush();
        $this->addFlash('success', 'Host smazán.');

        return $this->redirectToRoute('checkin_index', ['token' => $token]);
    }

    #[Route('/checkin/{token}/hotovo', name: 'checkin_finish', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function finish(string $token, Request $request): Response
    {
        $reservation = $this->resolveReservation($token);
        $this->guardEditable($reservation);

        if (!$this->isCsrfTokenValid('checkin_finish', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Neplatný formulář, zkuste to znovu.');

            return $this->redirectToRoute('checkin_index', ['token' => $token]);
        }

        // Zahraniční hosty hlásíme na Ubyport; čistě česká skupina nevyplňuje nic,
        // takže dokončení s nula doklady je legitimní (host potvrdil „jen Češi").
        $reservation->markCheckinCompleted();
        $this->em->flush();

        return $this->redirectToRoute('checkin_thanks', ['token' => $token]);
    }

    #[Route('/checkin/{token}/dekujeme', name: 'checkin_thanks', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function thanks(string $token): Response
    {
        $reservation = $this->resolveReservation($token);

        return $this->render('checkin/thanks.html.twig', [
            'reservation' => $reservation,
        ]);
    }

    #[Route('/checkin/{token}/mrz', name: 'checkin_mrz', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function parseMrz(string $token, Request $request): JsonResponse
    {
        $this->resolveReservation($token);

        $payload = json_decode((string) $request->getContent(), true);

        // The browser OCRs the photo several ways (preprocessing variants) and
        // sends all reads; we merge them by per-field majority vote. A single
        // `mrz` text (manual entry / legacy) is still accepted.
        $texts = $payload['mrz_texts'] ?? null;
        if (\is_array($texts)) {
            $texts = array_values(array_filter(
                array_map(static fn ($t) => \is_string($t) ? $t : '', $texts),
                static fn (string $t) => trim($t) !== '',
            ));
            if ($texts === []) {
                return new JsonResponse(['error' => 'Chybí MRZ text.'], 400);
            }
            $result = $this->mrzParser->parseMany($texts);
        } else {
            $mrzText = $payload['mrz'] ?? '';
            if (!\is_string($mrzText) || trim($mrzText) === '') {
                return new JsonResponse(['error' => 'Chybí MRZ text.'], 400);
            }
            $result = $this->mrzParser->parse($mrzText);
        }

        if ($result === null) {
            return new JsonResponse(['error' => 'Nepodařilo se rozpoznat MRZ zónu dokladu.'], 422);
        }

        $data = $result->toArray();
        // Confidence lets the browser gate its locate-then-recrop pass: a
        // checksum-solid read is accepted, otherwise it falls back to the
        // whole-image passes before prefilling.
        $data['confidence'] = $result->confidence;

        $nationality = $this->nationalities->find($result->nationalityCode);
        $data['nationalityFound'] = $nationality !== null;
        $data['nationalityLabel'] = $nationality
            ? sprintf('%s — %s', $nationality->getCode(), $nationality->getNameCs())
            : null;

        return new JsonResponse($data);
    }

    private function resolveReservation(string $token): Reservation
    {
        $reservation = $this->reservations->findOneBy(['checkinToken' => $token]);
        if ($reservation === null) {
            throw new NotFoundHttpException();
        }

        return $reservation;
    }

    private function resolveDocument(Reservation $reservation, int $id): GuestDocument
    {
        $doc = $this->documents->find($id);
        if ($doc === null || $doc->getReservation()->getId() !== $reservation->getId()) {
            throw new NotFoundHttpException();
        }

        return $doc;
    }

    private function guardEditable(Reservation $reservation): void
    {
        if ($reservation->getCheckinCompletedAt() !== null) {
            throw new NotFoundHttpException('Check-in již byl uzavřen.');
        }
    }

    private function handleForm(Request $request, GuestDocument $doc, string $token, bool $isNew): Response
    {
        $form = $this->createForm(GuestDocumentType::class, $doc);
        if (!$isNew && $doc->getNationalityCode() !== null) {
            $form->get('nationality')->setData($this->nationalities->find($doc->getNationalityCode()));
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $nationality = $form->get('nationality')->getData();
            $doc->setNationalityCode($nationality?->getCode());

            if ($doc->isCzechCitizen()) {
                $doc->clearForeignerFields();
            }

            $doc->confirm();

            if ($isNew) {
                $this->em->persist($doc);
            }
            $this->em->flush();
            $this->addFlash('success', 'Údaje uloženy.');

            return $this->redirectToRoute('checkin_index', ['token' => $token]);
        }

        return $this->render('checkin/host.html.twig', [
            'form' => $form->createView(),
            'token' => $token,
            'isNew' => $isNew,
            'doc' => $doc,
        ]);
    }

    private function expectedGuestCount(Reservation $reservation): int
    {
        return $reservation->getGuestsAdult() + $reservation->getGuestsChild() + $reservation->getGuestsInfant();
    }

    /**
     * Fakturační adresa je „vyplněná", máme-li jméno i ulici objednatele — to je
     * minimum, aby šlo vystavit fakturu. Podle toho check-in nabídne doplnění.
     */
    private function hasBillingAddress(Reservation $reservation): bool
    {
        return trim((string) $reservation->getGuestName()) !== ''
            && trim((string) $reservation->getGuestStreet()) !== '';
    }
}
