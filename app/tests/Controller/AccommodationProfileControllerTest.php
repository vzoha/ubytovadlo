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
use App\Entity\Embeddable\PropertyAddress;
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
            'property[address][okres]' => 'Mladá Boleslav',
            'property[address][obec]' => 'Ukázkov',
            'property[address][castObce]' => 'Lhota',
            'property[address][cp]' => '12',
            'property[address][psc]' => '29464',
        ]));

        self::assertResponseRedirects('/nastaveni/ubytovani', 302, (string) $this->client->getResponse()->getContent());

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertNotNull($profile);
        self::assertSame('Apartmán Ukázka', $profile->getNazev());
        self::assertSame('Lhota', $profile->getAddress()->getCastObce());
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
        self::assertStringContainsString('Lniště 30, 374 01 Slavče', $crawler->filter('.reporting-address')->text());

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

    public function testReportNameIsSavedWithTheIdentifiers(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        self::assertSame(
            'Vejminek',
            $crawler->filter('[name="ubyport_identifiers[reportingName]"]')->attr('value'),
            'políčko se nabídne s názvem objektu',
        );

        $this->client->submit($crawler->selectButton('Uložit')->form($this->identifiers([
            'ubyport_identifiers[reportingName]' => 'Ubytovna Pošta, s. r. o.',
        ])));
        self::assertResponseRedirects('/nastaveni/ubyport');

        $profile = $this->storedProfile();
        self::assertSame('Vejminek', $profile->getNazev(), 'Název pro hosty zůstává');
        self::assertSame('Ubytovna Pošta, s. r. o.', $profile->nameForReport());
    }

    public function testReportNameIsRequired(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        $this->client->submit($crawler->selectButton('Uložit')->form($this->identifiers([
            'ubyport_identifiers[reportingName]' => '',
        ])));

        self::assertResponseIsSuccessful();
        self::assertNull($this->storedProfile()->getReportingName());
    }

    public function testAddressInReportComesFromThePropertyByDefault(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        $this->client->submit($crawler->selectButton('Uložit')->form($this->identifiers()));
        self::assertResponseRedirects('/nastaveni/ubyport');

        $profile = $this->storedProfile();
        self::assertFalse($profile->hasOwnReportingAddress());
        self::assertSame('Lniště 30, 374 01 Slavče', $profile->addressForReport()->format());
    }

    public function testDeviceRegisteredOnItsOwnAddress(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        $this->client->submit($crawler->selectButton('Uložit')->form($this->identifiers([
            'ubyport_identifiers[reportingSource]' => 'own',
            'ubyport_identifiers[reportingAddress][okres]' => 'Písek',
            'ubyport_identifiers[reportingAddress][obec]' => 'Písek',
            'ubyport_identifiers[reportingAddress][castObce]' => '',
            'ubyport_identifiers[reportingAddress][ulice]' => 'Velké náměstí',
            'ubyport_identifiers[reportingAddress][cp]' => '1',
            'ubyport_identifiers[reportingAddress][psc]' => '39701',
        ])));
        self::assertResponseRedirects('/nastaveni/ubyport');

        $profile = $this->storedProfile();
        self::assertSame('Velké náměstí 1, 397 01 Písek', $profile->addressForReport()->format());
        self::assertSame('Lniště 30, 374 01 Slavče', $profile->getAddress()->format(), 'Adresa objektu zůstává');
    }

    public function testOwnAddressMustBeComplete(): void
    {
        $this->persistProfile();

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        $this->client->submit($crawler->selectButton('Uložit')->form($this->identifiers([
            'ubyport_identifiers[reportingSource]' => 'own',
            'ubyport_identifiers[reportingAddress][okres]' => '',
            'ubyport_identifiers[reportingAddress][obec]' => '',
            'ubyport_identifiers[reportingAddress][castObce]' => '',
            'ubyport_identifiers[reportingAddress][ulice]' => '',
            'ubyport_identifiers[reportingAddress][cp]' => '',
            'ubyport_identifiers[reportingAddress][psc]' => '',
        ])));

        self::assertSelectorTextContains('body', 'úplnou adresu zařízení');
        self::assertFalse($this->storedProfile()->hasOwnReportingAddress(), 'nic se neuloží');
    }

    public function testSwitchingBackToThePropertyDropsTheOwnAddress(): void
    {
        $this->persistProfile(new PropertyAddress(okres: 'Písek', obec: 'Písek', ulice: 'Velké náměstí', cp: '1', psc: '39701'));

        $crawler = $this->client->request('GET', '/nastaveni/ubyport');
        $this->client->submit($crawler->selectButton('Uložit')->form($this->identifiers([
            'ubyport_identifiers[reportingSource]' => 'property',
        ])));
        self::assertResponseRedirects('/nastaveni/ubyport');

        $profile = $this->storedProfile();
        self::assertFalse($profile->hasOwnReportingAddress());
        self::assertSame('Lniště 30, 374 01 Slavče', $profile->addressForReport()->format());
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function identifiers(array $overrides = []): array
    {
        return array_replace([
            'ubyport_identifiers[idub]' => '111122223333',
            'ubyport_identifiers[kod]' => 'OLD',
            'ubyport_identifiers[reportingName]' => 'Vejminek',
            'ubyport_identifiers[spojeni]' => 'Jan Novák, tel: 777 000 000',
        ], $overrides);
    }

    private function storedProfile(): AccommodationProfile
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertNotNull($profile);

        return $profile;
    }

    private function persistProfile(?PropertyAddress $reportingAddress = null): void
    {
        $profile = new AccommodationProfile();
        $profile->setIdub('111122223333');
        $profile->setKod('OLD');
        $profile->setNazev('Vejminek');
        $profile->setSpojeni('Jan Novák, tel: 777 000 000');
        $profile->setAddress(new PropertyAddress(
            okres: 'České Budějovice',
            obec: 'Slavče',
            castObce: 'Lniště',
            cp: '30',
            psc: '37401',
        ));
        if ($reportingAddress !== null) {
            $profile->setReportingAddress($reportingAddress);
        }
        $this->em->persist($profile);
        $this->em->flush();
        $this->em->clear();
    }
}
