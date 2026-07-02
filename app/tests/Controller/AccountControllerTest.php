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
use App\Entity\Reservation;
use App\Entity\ReservationReceipt;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\ExpenseCategory;
use App\Enum\IncomeSource;
use App\Enum\LedgerEntryType;
use App\Enum\ReceiptOrigin;
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

        foreach ([ReservationReceipt::class, LedgerEntry::class, BalanceStatement::class, Account::class, User::class] as $class) {
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

    public function testAddIncomeUpdatesBalance(): void
    {
        $crawler = $this->client->request('GET', '/ucty');
        $form = $crawler->filter('form[action="/ucty/prijem"]')->form();
        $form['account'] = (string) $this->bank->getId();
        $form['occurred_on'] = '2026-03-10';
        $form['amount'] = '500';
        $form['note'] = 'Úroky';
        $this->client->submit($form);

        self::assertResponseRedirects('/ucty');
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Příjem zapsán.', $body);
        // 1000 + 500 = 1500
        self::assertStringContainsString('1 500 Kč', $body);
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

    public function testEditEntryUpdatesAmountAndCategory(): void
    {
        $entry = new LedgerEntry(LedgerEntryType::EXPENSE, new \DateTimeImmutable('2026-02-10'), 300, $this->bank);
        $entry->setCategory(ExpenseCategory::MAINTENANCE);
        $this->em->persist($entry);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/ucty/pohyb/' . $entry->getId() . '/upravit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['amount'] = '450';
        $form['category'] = 'laundry';
        $form['note'] = 'Oprava';
        $this->client->submit($form);

        self::assertResponseRedirects('/ucty');
        $this->em->clear();
        $updated = $this->em->getRepository(LedgerEntry::class)->find($entry->getId());
        self::assertNotNull($updated);
        self::assertSame(450, $updated->getAmountCzk());
        self::assertSame(ExpenseCategory::LAUNDRY, $updated->getCategory());
    }

    public function testEditAccountRenamesAndTogglesActive(): void
    {
        $crawler = $this->client->request('GET', '/ucty/' . $this->bank->getId() . '/upravit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['name'] = 'Přejmenovaný účet';
        $this->client->submit($form);

        self::assertResponseRedirects('/ucty/' . $this->bank->getId());
        $this->em->clear();
        $updated = $this->em->getRepository(Account::class)->find($this->bank->getId());
        self::assertSame('Přejmenovaný účet', $updated?->getName());
    }

    public function testFilterByTypeLimitsMovements(): void
    {
        $expense = new LedgerEntry(LedgerEntryType::EXPENSE, new \DateTimeImmutable('2026-02-10'), 300, $this->bank);
        $expense->setNote('VYDAJ-MARKER');
        $adjustment = new LedgerEntry(LedgerEntryType::ADJUSTMENT, new \DateTimeImmutable('2026-02-11'), 50, $this->bank);
        $adjustment->setNote('KOREKCE-MARKER');
        $this->em->persist($expense);
        $this->em->persist($adjustment);
        $this->em->flush();

        $this->client->request('GET', '/ucty?type=expense');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('VYDAJ-MARKER', $body);
        self::assertStringNotContainsString('KOREKCE-MARKER', $body);
    }

    public function testCsvExportReturnsAttachment(): void
    {
        $entry = new LedgerEntry(LedgerEntryType::EXPENSE, new \DateTimeImmutable('2026-02-10'), 300, $this->bank);
        $entry->setNote('CSV-RADEK');
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->request('GET', '/ucty/export.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('CSV-RADEK', (string) $this->client->getResponse()->getContent());
    }

    public function testMonthlySummaryRenders(): void
    {
        $this->client->request('GET', '/ucty/souhrn/2026');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Měsíční souhrn cashflow 2026', (string) $this->client->getResponse()->getContent());
    }

    public function testInvalidFilterDateDoesNotCrash(): void
    {
        // Nevalidní datum z query nesmí shodit stránku (500) — filtr ho ignoruje.
        $this->client->request('GET', '/ucty?from=2026-99-99&to=nesmysl');

        self::assertResponseIsSuccessful();
    }

    public function testReceivedIncomesArePaginated(): void
    {
        // Víc než jedna stránka přijatých příjmů → musí být paginace (dřív jen limit 20).
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-01-01'));
        $reservation->setGuestName('Paginace Test');
        $this->em->persist($reservation);
        for ($i = 1; $i <= 25; $i++) {
            $receipt = new ReservationReceipt($reservation, '100.00', IncomeSource::PAID_INVOICE, ReceiptOrigin::INVOICE, $i);
            $receipt->setAccount($this->bank);
            $receipt->setReceivedOn(new \DateTimeImmutable('2026-02-01'));
            $this->em->persist($receipt);
        }
        $this->em->flush();

        $this->client->request('GET', '/ucty');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('rpage=2', (string) $this->client->getResponse()->getContent());
    }
}
