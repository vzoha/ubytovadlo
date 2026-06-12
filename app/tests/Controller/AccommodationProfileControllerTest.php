<?php

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
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser(
            $container->get(UserRepository::class)->findOneBy(['email' => 'profile-test@example.com']),
        );
    }

    public function testEmptyFormShowsWarning(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'ještě nebyl vyplněn');
    }

    public function testCreateProfile(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');
        $form = $crawler->selectButton('Uložit')->form([
            'accommodation_profile[idub]' => '123456789012',
            'accommodation_profile[kod]' => 'VEJMI',
            'accommodation_profile[nazev]' => 'Apartmán Ukázka',
            'accommodation_profile[spojeni]' => 'Jan Novák, tel: 777 000 000',
            'accommodation_profile[okres]' => 'Mladá Boleslav',
            'accommodation_profile[obec]' => 'Ukázkov',
            'accommodation_profile[castObce]' => '',
            'accommodation_profile[ulice]' => '',
            'accommodation_profile[cp]' => '12',
            'accommodation_profile[co]' => '',
            'accommodation_profile[psc]' => '29464',
        ]);

        $this->client->submit($form);
        self::assertResponseRedirects('/nastaveni/ubytovani');

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertNotNull($profile);
        self::assertSame('123456789012', $profile->getIdub());
        self::assertSame('VEJMI', $profile->getKod());
    }

    public function testUpdateExistingProfileDoesNotCreateDuplicate(): void
    {
        $existing = new AccommodationProfile();
        $existing->setIdub('111122223333');
        $existing->setKod('OLD');
        $existing->setNazev('Stary nazev');
        $existing->setSpojeni('X');
        $existing->setOkres('X');
        $existing->setObec('X');
        $existing->setPsc('29464');
        $this->em->persist($existing);
        $this->em->flush();
        $originalId = $existing->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');
        $form = $crawler->selectButton('Uložit')->form([
            'accommodation_profile[idub]' => '999988887777',
            'accommodation_profile[kod]' => 'NOVY',
            'accommodation_profile[nazev]' => 'Novy nazev',
            'accommodation_profile[spojeni]' => 'Y',
            'accommodation_profile[okres]' => 'Y',
            'accommodation_profile[obec]' => 'Y',
            'accommodation_profile[psc]' => '38901',
        ]);

        $this->client->submit($form);

        $repo = static::getContainer()->get(AccommodationProfileRepository::class);
        self::assertCount(1, $repo->findAll(), 'po updatu nesmi vzniknout druhy radek');
        $updated = $repo->getSingleton();
        self::assertSame($originalId, $updated->getId(), 'menime tentyz radek');
        self::assertSame('999988887777', $updated->getIdub());
        self::assertSame('NOVY', $updated->getKod());
    }

    public function testKodIsStoredUppercase(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');
        $form = $crawler->selectButton('Uložit')->form([
            'accommodation_profile[idub]' => '123456789012',
            'accommodation_profile[kod]' => 'vejmi',
            'accommodation_profile[nazev]' => 'X',
            'accommodation_profile[spojeni]' => 'X',
            'accommodation_profile[okres]' => 'X',
            'accommodation_profile[obec]' => 'X',
            'accommodation_profile[psc]' => '29464',
        ]);

        $this->client->submit($form);

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertSame('VEJMI', $profile->getKod());
    }

    public function testInvalidIdubRejected(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ubytovani');
        $form = $crawler->selectButton('Uložit')->form([
            'accommodation_profile[idub]' => 'NENI-CISLO',
            'accommodation_profile[kod]' => 'VEJMI',
            'accommodation_profile[nazev]' => 'X',
            'accommodation_profile[spojeni]' => 'X',
            'accommodation_profile[okres]' => 'X',
            'accommodation_profile[obec]' => 'X',
            'accommodation_profile[psc]' => '29464',
        ]);

        $this->client->submit($form);

        // Form se znovuvykreslí s chybou; nesmí se nic uložit
        self::assertSelectorTextContains('body', 'IDUB musí být 12 číslic');

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertNull($profile);
    }
}
