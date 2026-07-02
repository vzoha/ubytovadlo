<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\BalanceStatement;
use App\Entity\LedgerEntry;
use App\Entity\ReservationIncome;
use App\Entity\User;
use App\Enum\AccountType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Account $bank;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        foreach ([ReservationIncome::class, LedgerEntry::class, BalanceStatement::class, Account::class, User::class] as $class) {
            $this->em->createQuery('DELETE FROM ' . $class . ' e')->execute();
        }

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('accounts-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);

        $this->bank = new Account('Testovací účet', AccountType::BANK, 1000, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($this->bank);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'accounts-test@example.com']));
    }

    public function testIndexRendersAccounts(): void
    {
        $this->client->request('GET', '/ucty');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Testovací účet', $body);
        self::assertStringContainsString('1 000 Kč', $body);
    }

    public function testAddExpenseUpdatesBalance(): void
    {
        $crawler = $this->client->request('GET', '/ucty');
        $form = $crawler->filter('form[action="/ucty/vydaj"]')->form();
        $form['account'] = (string) $this->bank->getId();
        $form['occurred_on'] = '2026-02-10';
        $form['amount'] = '300';
        $form['category'] = 'maintenance';
        $form['note'] = 'Revize';
        $this->client->submit($form);

        self::assertResponseRedirects('/ucty');
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Výdaj zapsán.', $body);
        // 1000 − 300 = 700
        self::assertStringContainsString('700 Kč', $body);
    }

    public function testAddStatementShowsDifference(): void
    {
        $crawler = $this->client->request('GET', '/ucty');
        $form = $crawler->filter('form[action="/ucty/uzaverka"]')->form();
        $form['account'] = (string) $this->bank->getId();
        $form['statement_date'] = '2026-03-01';
        $form['actual_balance'] = '1500';
        $this->client->submit($form);

        self::assertResponseRedirects('/ucty/' . $this->bank->getId());
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Uzávěrky', $body);
        // očekáváno 1000, reálně 1500, rozdíl 500
        self::assertStringContainsString('Srovnat korekcí', $body);
    }

    public function testExpenseWithoutCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/ucty/vydaj', [
            'account' => (string) $this->bank->getId(),
            'amount' => '100',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
