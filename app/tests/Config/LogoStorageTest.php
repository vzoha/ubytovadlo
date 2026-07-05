<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\LogoStorage;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AllowMockObjectsWithoutExpectations]
final class LogoStorageTest extends TestCase
{
    /** 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/logo-test-' . uniqid();
        mkdir($this->root . '/public/assets', 0o777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/public/assets/*') ?: []);
        @rmdir($this->root . '/public/assets');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
    }

    public function testDefaultsToLogoPngWhenSettingUnset(): void
    {
        $storage = new LogoStorage($this->settingsReturning(null), $this->em(), $this->root);

        self::assertSame('/assets/logo.png', $storage->publicPath());
        self::assertFalse($storage->exists());

        file_put_contents($this->root . '/public/assets/logo.png', 'x');
        self::assertTrue($storage->exists());
    }

    public function testStorePngSavesFileAndSetting(): void
    {
        $captured = null;
        $settings = $this->settingsReturning(null);
        $settings->method('set')->willReturnCallback(
            function (string $key, string $value) use (&$captured): Setting {
                $captured = [$key, $value];

                return new Setting($key, $value);
            },
        );

        $storage = new LogoStorage($settings, $this->em(), $this->root);
        $storage->store($this->upload('logo.png'));

        self::assertFileExists($this->root . '/public/assets/logo.png');
        self::assertSame([LogoStorage::SETTING_KEY, 'logo.png'], $captured);
    }

    public function testStoreReplacesPreviousDifferentExtension(): void
    {
        file_put_contents($this->root . '/public/assets/logo.jpg', 'old');
        $storage = new LogoStorage($this->settingsReturning('logo.jpg'), $this->em(), $this->root);

        $storage->store($this->upload('logo.png'));

        self::assertFileDoesNotExist($this->root . '/public/assets/logo.jpg');
        self::assertFileExists($this->root . '/public/assets/logo.png');
    }

    public function testStoreRejectsUnsupportedFormat(): void
    {
        $path = $this->root . '/junk.txt';
        file_put_contents($path, 'not an image');
        $storage = new LogoStorage($this->settingsReturning(null), $this->em(), $this->root);

        $this->expectException(\InvalidArgumentException::class);
        $storage->store(new UploadedFile($path, 'junk.txt', null, null, true));
    }

    public function testRemoveDeletesFile(): void
    {
        file_put_contents($this->root . '/public/assets/logo.png', 'x');
        $storage = new LogoStorage($this->settingsReturning('logo.png'), $this->em(), $this->root);

        $storage->remove();

        self::assertFileDoesNotExist($this->root . '/public/assets/logo.png');
    }

    /** @return SettingRepository&\PHPUnit\Framework\MockObject\MockObject */
    private function settingsReturning(?string $filename): SettingRepository
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn($filename);
        $settings->method('set')->willReturnCallback(
            static fn (string $key, string $value): Setting => new Setting($key, $value),
        );

        return $settings;
    }

    private function em(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    private function upload(string $name): UploadedFile
    {
        $path = $this->root . '/upload-' . $name;
        file_put_contents($path, base64_decode(self::PNG));

        return new UploadedFile($path, $name, null, null, true);
    }
}
