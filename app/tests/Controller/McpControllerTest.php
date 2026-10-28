<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Mcp\McpAccessToken;
use App\Mcp\Tool\PropertyInfoTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->getConnection()->executeStatement('DELETE FROM reservation');
        $this->em->getConnection()->executeStatement('DELETE FROM customer');
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();

        $this->token = static::getContainer()->get(McpAccessToken::class)->issue();
    }

    public function testMissingOrWrongTokenIsUnauthorized(): void
    {
        $this->client->request('POST', '/mcp', content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHasHeader('WWW-Authenticate');

        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer ubyt_wrong'], content: '{}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedTokenIsUnauthorized(): void
    {
        static::getContainer()->get(McpAccessToken::class)->revoke();

        $this->call('ping');
        self::assertResponseStatusCodeSame(401);
    }

    public function testGetIsNotAllowed(): void
    {
        $this->client->request('GET', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testNotificationIsAcceptedWithoutBody(): void
    {
        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token], content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        self::assertResponseStatusCodeSame(202);
    }

    public function testToolsListContainsAllTools(): void
    {
        $names = array_column($this->call('tools/list')['result']['tools'], 'name');

        sort($names);
        self::assertSame(['check_availability', 'find_guest', 'get_property_info', 'get_reservation', 'list_guest_emails', 'read_guest_email', 'reply_to_guest_email'], $names);
    }

    public function testAvailabilityTreatsCheckoutDayAsFree(): void
    {
        $this->reservation('2030-07-05', '2030-07-12');

        $touching = $this->tool('check_availability', ['checkIn' => '2030-07-12', 'checkOut' => '2030-07-19']);
        $overlap = $this->tool('check_availability', ['checkIn' => '2030-07-10', 'checkOut' => '2030-07-14']);

        self::assertTrue($touching['free']);
        self::assertFalse($overlap['free']);
        self::assertSame([['checkIn' => '2030-07-05', 'checkOut' => '2030-07-12', 'channel' => 'Web']], $overlap['occupied']);
    }

    public function testAvailabilityIgnoresCancelledAndHidesGuest(): void
    {
        $this->reservation('2030-08-01', '2030-08-05', status: ReservationStatus::CANCELLED);
        $this->reservation('2030-08-10', '2030-08-12');

        $result = $this->tool('check_availability', ['checkIn' => '2030-08-01', 'checkOut' => '2030-08-20']);

        self::assertCount(1, $result['occupied']);
        self::assertStringNotContainsString('Jana', (string) json_encode($result));
    }

    public function testAvailabilityRejectsInvalidRange(): void
    {
        $reply = $this->call('tools/call', ['name' => 'check_availability', 'arguments' => ['checkIn' => '2030-08-05', 'checkOut' => '2030-08-01']]);

        self::assertTrue($reply['result']['isError']);
    }

    public function testFindGuestReturnsStaysAndReservationDetail(): void
    {
        $this->reservation('2030-07-05', '2030-07-12');

        $found = $this->tool('find_guest', ['query' => 'jana@example.com']);
        self::assertSame(1, $found['found']);
        self::assertSame('Jana Testová', $found['guests'][0]['name']);
        self::assertCount(1, $found['guests'][0]['stays']);

        $detail = $this->tool('get_reservation', ['id' => $found['guests'][0]['stays'][0]['id']]);
        self::assertSame('jana@example.com', $detail['email']);
        self::assertSame(7, $detail['nights']);
    }

    public function testFindGuestNeedsThreeCharacters(): void
    {
        $reply = $this->call('tools/call', ['name' => 'find_guest', 'arguments' => ['query' => 'ja']]);

        self::assertTrue($reply['result']['isError']);
    }

    public function testPropertyInfoReturnsOwnerText(): void
    {
        $this->em->persist(new Setting(PropertyInfoTool::SETTING_KEY, 'Pes za 200 Kč/noc.'));
        $this->em->flush();

        self::assertSame('Pes za 200 Kč/noc.', $this->tool('get_property_info', [])['ownerInfo']);
    }

    private function reservation(string $checkIn, string $checkOut, ReservationStatus $status = ReservationStatus::CONFIRMED): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
        $r->setCheckOut(new \DateTimeImmutable($checkOut));
        $r->setStatus($status);
        $r->setGuestName('Jana Testová');
        $r->setGuestContact(new GuestContact('jana@example.com'));
        $this->em->persist($r);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $reply = $this->call('tools/call', ['name' => $name, 'arguments' => $arguments]);
        self::assertFalse($reply['result']['isError'], $reply['result']['content'][0]['text'] ?? '');

        return json_decode($reply['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params = []): array
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: new \stdClass()], JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
