<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Task;

use App\Entity\RecurringTask;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Protokoly a revizní zprávy k provedeným úkonům. V DB se drží relativní cesta
 * (`var/tasks/12/2026-03-04-revize.pdf`), aby přenos databáze mezi prostředími
 * nevyžadoval přepisování cest.
 */
final class TaskAttachmentStorage
{
    private const RELATIVE_ROOT = 'var/tasks';

    public function __construct(private readonly string $projectDir)
    {
    }

    /** Uloží nahraný soubor a vrátí relativní cestu. */
    public function store(UploadedFile $file, RecurringTask $task, \DateTimeImmutable $doneOn): string
    {
        $folder = sprintf('%s/%d', self::RELATIVE_ROOT, (int) $task->getId());
        $dir = $this->projectDir . '/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Nelze vytvořit adresář: %s', $dir));
        }

        $name = sprintf('%s-%s', $doneOn->format('Y-m-d'), $this->safeName($file));
        $file->move($dir, $name);

        return $folder . '/' . $name;
    }

    /** Absolutní cesta k uloženému souboru. */
    public function absolute(string $storedPath): string
    {
        return str_starts_with($storedPath, '/') ? $storedPath : $this->projectDir . '/' . $storedPath;
    }

    public function exists(string $storedPath): bool
    {
        return is_file($this->absolute($storedPath));
    }

    public function delete(?string $storedPath): void
    {
        if ($storedPath === null || !$this->exists($storedPath)) {
            return;
        }

        unlink($this->absolute($storedPath));
    }

    /** Název souboru bez znaků, které by mohly ublížit cestě. */
    private function safeName(UploadedFile $file): string
    {
        $original = $file->getClientOriginalName();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $original);

        return $safe === null || $safe === '' ? 'protokol.pdf' : substr($safe, -120);
    }
}
