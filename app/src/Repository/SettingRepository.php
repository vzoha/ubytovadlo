<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        return $this->find($key)?->getValue() ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->find($key)?->getValue();

        return $value === null ? $default : (int) $value;
    }

    public function set(string $key, string $value, ?string $note = null): Setting
    {
        $setting = $this->find($key) ?? new Setting($key, $value);
        $setting->setValue($value);
        if ($note !== null) {
            $setting->setNote($note);
        }
        $this->getEntityManager()->persist($setting);

        return $setting;
    }
}
