<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccommodationProfile;
use App\Entity\User;
use App\Repository\AccommodationProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccommodationProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM ' . AccommodationProfile::class . ' p')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();
        $this->em->flush();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('profile-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser(
            $container->get(UserRepository::class)->findOneBy(['email' => 'profile-test@example.com']),
        );
    }

    public function testEmptyPropertyShowsWarning(): void
    {
        $this->client->request('GET', '/nastaveni/ubytovani');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'ještě nejsou vyplněné');
    }

    public function testPropertyPageSavesNameAndAddress(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');
        $this->client->submit($crawler->selectButton('Uložit')->form([
            'property[nazev]' => 'Apartmán Ukázka',
            'property[okres]' => 'Mladá Boleslav',
            'property[obec]' => 'Ukázkov',
            'property[castObce]' => 'Lhota',
            'property[cp]' => '12',
            'property[psc]' => '29464',
        ]));

        self::assertResponseRedirects('/nastaveni/ubytovani', 302, (string) $this->client->getResponse()->getContent());

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertNotNull($profile);
        self::assertSame('Apartmán Ukázka', $profile->getNazev());
        self::assertSame('Lhota', $profile->getCastObce());
    }

    public function testPropertyPageDoesNotAskForUbyportIdentifiers(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');

        self::assertCount(0, $crawler->filter('[name="property[idub]"]'));
        self::assertGreaterThan(0, $crawler->filter('a[href="/nastaveni/ubyport"]')->count());
    }

    public function testUbyportPageSavesIdentifiersAndShowsPropertyAddress(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Lniště 30, 374 01 Slavče', $crawler->filter('.card')->last()->text());

        $this->client->submit($crawler->selectButton('Uložit')->form([
            'ubyport_identifiers[idub]' => '999988887777',
            'ubyport_identifiers[kod]' => 'novy',
            'ubyport_identifiers[spojeni]' => 'Jan Novák, tel: 777 000 000',
        ]));

        self::assertResponseRedirects('/nastaveni/ubyport');

        $repo = static::getContainer()->get(AccommodationProfileRepository::class);
        self::assertCount(1, $repo->findAll(), 'po updatu nesmi vzniknout druhy radek');
        self::assertSame('999988887777', $repo->getSingleton()->getIdub());
        self::assertSame('NOVY', $repo->getSingleton()->getKod(), 'kód se ukládá velkými písmeny');
    }

    public function testInvalidIdubRejected(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        $this->client->submit($crawler->selectButton('Uložit')->form([
            'ubyport_identifiers[idub]' => 'NENI-CISLO',
            'ubyport_identifiers[kod]' => 'VEJMI',
            'ubyport_identifiers[spojeni]' => 'Jan Novák, tel: 777 000 000',
        ]));

        self::assertSelectorTextContains('body', 'IDUB musí být 12 číslic');

        // Čteme mimo identity mapu requestu, ať je vidět, co je opravdu v databázi.
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        self::assertSame('111122223333', static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton()->getIdub());
    }

    private function persistProfile(): void
    {
        $profile = new AccommodationProfile();
        $profile->setIdub('111122223333');
        $profile->setKod('OLD');
        $profile->setNazev('Vejminek');
        $profile->setSpojeni('Jan Novák, tel: 777 000 000');
        $profile->setOkres('České Budějovice');
        $profile->setObec('Slavče');
        $profile->setCastObce('Lniště');
        $profile->setCp('30');
        $profile->setPsc('37401');
        $this->em->persist($profile);
        $this->em->flush();
        $this->em->clear();
    }
}
