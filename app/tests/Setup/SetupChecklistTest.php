<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Config\InstanceSettings;
use App\Entity\AccommodationProfile;
use App\Entity\Credential;
use App\Entity\Setting;
use App\Invoice\IssuerProfileProvider;
use App\Repository\SettingRepository;
use App\Setup\SetupChecklist;
use App\Setup\SetupChecklistItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetupChecklistTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SettingRepository $settings;
    private SetupChecklist $checklist;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->settings = $container->get(SettingRepository::class);
        $this->checklist = $container->get(SetupChecklist::class);

        $this->em->createQuery('DELETE FROM ' . AccommodationProfile::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . Credential::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
    }

    public function testEmptyInstanceHasEverythingPending(): void
    {
        self::assertEqualsCanonicalizing(
            ['instance', 'issuer', 'mail', 'smtp', 'imap', 'motopress', 'accommodation'],
            $this->pendingKeys(),
        );
        self::assertSame(0, $this->checklist->dismissedCount());
    }

    public function testConfiguringItemsRemovesThemFromPending(): void
    {
        $this->settings->set(InstanceSettings::KEY_BRAND_NAME, 'Můj penzion');
        $this->settings->set(IssuerProfileProvider::KEYS['name'], 'Jan Novák');
        $this->settings->set(IssuerProfileProvider::KEYS['ico'], '12345678');
        $this->em->flush();

        $pending = $this->pendingKeys();
        self::assertNotContains('instance', $pending);
        self::assertNotContains('issuer', $pending);
    }

    public function testAccommodationProfileMarksItemConfigured(): void
    {
        $profile = new AccommodationProfile();
        $profile->setIdub('111122223333');
        $profile->setKod('ABC');
        $profile->setNazev('Vejminek');
        $profile->setSpojeni('X');
        $profile->setOkres('X');
        $profile->setObec('X');
        $profile->setPsc('29464');
        $this->em->persist($profile);
        $this->em->flush();

        self::assertNotContains('accommodation', $this->pendingKeys());
    }

    public function testDismissHidesItemAndRestoreBringsItBack(): void
    {
        $this->checklist->dismiss('smtp');

        self::assertNotContains('smtp', $this->pendingKeys());
        self::assertSame(1, $this->checklist->dismissedCount());

        $this->checklist->restore();

        self::assertContains('smtp', $this->pendingKeys());
        self::assertSame(0, $this->checklist->dismissedCount());
    }

    public function testDismissIgnoresUnknownKey(): void
    {
        $this->checklist->dismiss('neexistuje');

        self::assertSame(0, $this->checklist->dismissedCount());
        self::assertNull($this->settings->getString('setup.dismissed.neexistuje'));
    }

    /** @return list<string> */
    private function pendingKeys(): array
    {
        return array_map(static fn (SetupChecklistItem $i): string => $i->key, $this->checklist->pending());
    }
}
