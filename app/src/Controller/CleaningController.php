<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Cleaning;
use App\Repository\CleaningRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CleaningController extends AbstractController
{
    public function __construct(
        private readonly CleaningRepository $cleanings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/uklid', name: 'cleaning_index', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable('first day of this month');
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        return $this->render('cleaning/index.html.twig', [
            'pending' => $this->cleanings->findPending(),
            'paid_this_month' => $this->cleanings->findPaidInMonth($year, $month),
            'month_label' => $now->format('n/Y'),
        ]);
    }

    #[Route('/uklid/{id}/zaplaceno', name: 'cleaning_mark_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markPaid(Cleaning $cleaning, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('cleaning-paid-' . $cleaning->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $cleaning->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Označeno jako vyplacené.');

        return $this->redirectToRoute('cleaning_index');
    }
}
