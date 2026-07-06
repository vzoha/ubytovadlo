<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Connector\ConnectorManager;
use App\Entity\Connector;
use App\Enum\ConnectorType;
use App\MotoPress\MotoPressSync;
use App\MotoPress\SyncResult;
use App\Repository\CredentialRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MotoPressWebhookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . Connector::class . ' c')->execute();

        // Token zakládáme přímo přes entitu (ne přes ConnectorManager) — jinak by se
        // v setUp instancoval CredentialRepository a testy s vyplněnými přístupy by ho
        // pak nemohly nahradit mockem (kontejner službu inicializuje jednou).
        $this->token = bin2hex(random_bytes(32));
        $connector = new Connector(ConnectorType::MOTOPRESS);
        $connector->setConfigValue(ConnectorManager::WEBHOOK_TOKEN_KEY, $this->token);
        $this->em->persist($connector);
        $this->em->flush();
    }

    private function url(string $token): string
    {
        return '/webhook/motopress/' . $token;
    }

    public function testWrongTokenIsNotFoundWithoutLogin(): void
    {
        $this->client->request('POST', $this->url(str_repeat('b', 64)), ['booking_id' => 42]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDisabledConnectorSkips(): void
    {
        static::getContainer()->get(ConnectorManager::class)->setEnabled(ConnectorType::MOTOPRESS, false);

        $this->client->request('POST', $this->url($this->token), ['booking_id' => 42]);

        self::assertResponseIsSuccessful();
        self::assertSame('skipped', $this->decode()['status']);
        self::assertSame('connector_disabled', $this->decode()['reason']);
    }

    public function testConfiguredMissingCredentialsSkips(): void
    {
        // Zapnutý konektor, ale bez přístupů (prázdná DB) → skipped, ne chyba.
        $this->client->request('POST', $this->url($this->token), ['booking_id' => 42]);

        self::assertResponseIsSuccessful();
        self::assertSame('not_configured', $this->decode()['reason']);
    }

    public function testMissingBookingIdIsBadRequest(): void
    {
        $this->fakeConfigured();

        $this->client->request('POST', $this->url($this->token));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('missing_booking_id', $this->decode()['reason']);
    }

    public function testMalformedJsonBodyIsBadRequest(): void
    {
        $this->fakeConfigured();

        $this->client->request('POST', $this->url($this->token), [], [], ['CONTENT_TYPE' => 'application/json'], '{booking_id');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_payload', $this->decode()['reason']);
    }

    public function testConcurrentImportIsIdempotent(): void
    {
        $this->fakeConfigured();

        // Cron poll rezervaci vytvořil ve stejný okamžik → unikátní klíč selže,
        // ale rezervace je naimportovaná: 200, ne 500.
        $sync = $this->createStub(MotoPressSync::class);
        $sync->method('syncById')->willThrowException($this->createStub(UniqueConstraintViolationException::class));
        static::getContainer()->set(MotoPressSync::class, $sync);

        $this->client->request('POST', $this->url($this->token), ['booking_id' => 1234]);

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame('ok', $body['status']);
        self::assertSame('already_imported', $body['reason']);
    }

    public function testValidPushImportsBookingById(): void
    {
        $this->fakeConfigured();

        $sync = $this->createMock(MotoPressSync::class);
        $sync->expects(self::once())
            ->method('syncById')
            ->with(1234)
            ->willReturn(new SyncResult(1, 0, 0, 1, 0));
        static::getContainer()->set(MotoPressSync::class, $sync);

        $this->client->request('POST', $this->url($this->token), ['booking_id' => 1234]);

        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertSame('ok', $body['status']);
        self::assertSame(1, $body['created']);
    }

    /** Předstírá vyplněné MotoPress přístupy — getDecrypted vrací pro každý klíč hodnotu. */
    private function fakeConfigured(): void
    {
        $credentials = $this->createStub(CredentialRepository::class);
        $credentials->method('getDecrypted')->willReturn('x');
        static::getContainer()->set(CredentialRepository::class, $credentials);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }
}
