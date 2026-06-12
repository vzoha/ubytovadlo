<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccommodationProfile;
use App\Form\AccommodationProfileType;
use App\Repository\AccommodationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccommodationProfileController extends AbstractController
{
    public function __construct(
        private readonly AccommodationProfileRepository $profiles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/ubytovani', name: 'accommodation_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $profile = $this->profiles->getSingleton() ?? new AccommodationProfile();

        $form = $this->createForm(AccommodationProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($profile->getId() === null) {
                $this->em->persist($profile);
            }
            $this->em->flush();
            $this->addFlash('success', 'Údaje ubytovacího zařízení uloženy.');

            return $this->redirectToRoute('accommodation_profile_edit');
        }

        return $this->render('accommodation_profile/edit.html.twig', [
            'form' => $form->createView(),
            'isNew' => $profile->getId() === null,
        ]);
    }
}
