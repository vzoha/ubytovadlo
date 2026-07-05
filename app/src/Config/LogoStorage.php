<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Logo instance — jeden soubor v public/assets, který se objeví v hlavičce e-mailů
 * hostům, na fakturách (PDF) i v náhledech. Nahrává se v Obecném nastavení; název
 * souboru (podle formátu) drží Setting, takže konzumenti nemusí znát příponu.
 */
final class LogoStorage
{
    public const SETTING_KEY = 'app.logo_filename';

    /** Výchozí název — existující instance mají logo.png na disku bez záznamu v DB. */
    private const DEFAULT_FILENAME = 'logo.png';

    /** Povolené MIME → přípona uloženého souboru. */
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->absolutePath());
    }

    /** Absolutní cesta k souboru loga (pro mPDF a inline přílohu e-mailu). */
    public function absolutePath(): string
    {
        return $this->assetsDir() . '/' . $this->filename();
    }

    /** Veřejná cesta pro <img src> (náhledy v prohlížeči). */
    public function publicPath(): string
    {
        return '/assets/' . $this->filename();
    }

    public function store(UploadedFile $file): void
    {
        $extension = self::EXTENSIONS[(string) $file->getMimeType()] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException('Nepodporovaný formát loga (povoleno PNG a JPG).');
        }

        $target = 'logo.' . $extension;
        $previous = $this->filename();
        if ($previous !== $target && is_file($this->assetsDir() . '/' . $previous)) {
            unlink($this->assetsDir() . '/' . $previous);
        }

        $file->move($this->assetsDir(), $target);
        $this->settings->set(self::SETTING_KEY, $target, 'Soubor loga instance.');
        $this->em->flush();
    }

    public function remove(): void
    {
        if ($this->exists()) {
            unlink($this->absolutePath());
        }
        $this->settings->set(self::SETTING_KEY, '', 'Soubor loga instance.');
        $this->em->flush();
    }

    private function filename(): string
    {
        return $this->settings->getString(self::SETTING_KEY) ?: self::DEFAULT_FILENAME;
    }

    private function assetsDir(): string
    {
        return $this->projectDir . '/public/assets';
    }
}
