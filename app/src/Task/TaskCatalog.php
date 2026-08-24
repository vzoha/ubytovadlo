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
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;

/**
 * Katalog obvyklých termínů ubytovatele — technické revize a kontroly se lhůtou
 * z předpisu, opakovaná administrativa, platby a sezónní údržba. Provozovatel si
 * z něj vybere, co se ho týká, a termín vznikne i s lhůtou a odkazem na předpis.
 *
 * Lhůty jsou obvyklé hodnoty pro malé ubytování; konkrétní zařízení může mít
 * lhůtu kratší (pokyn výrobce, prostředí, provozní řád), proto jsou po založení
 * volně editovatelné.
 */
final class TaskCatalog
{
    /** @var list<CatalogEntry>|null */
    private ?array $entries = null;

    /** @return list<CatalogEntry> */
    public function all(): array
    {
        return $this->entries ??= $this->build();
    }

    public function find(string $key): ?CatalogEntry
    {
        foreach ($this->all() as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<string, list<CatalogEntry>> kategorie → šablony */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->all() as $entry) {
            $grouped[$entry->category->value][] = $entry;
        }

        return $grouped;
    }

    /** Termín ze šablony. Datum prvního termínu zadává provozovatel. */
    public function toTask(CatalogEntry $entry, ?\DateTimeImmutable $dueOn = null): RecurringTask
    {
        $task = new RecurringTask($entry->name, $entry->category, $entry->recurrence);
        $task->setInterval($entry->intervalValue, $entry->intervalUnit)
            ->setLegalReference($entry->legalReference)
            ->setExpenseCategory($entry->category->defaultExpenseCategory())
            ->setCatalogKey($entry->key)
            ->setDueOn($dueOn);

        return $task;
    }

    /** @return list<CatalogEntry> */
    private function build(): array
    {
        return [
            ...$this->fireSafety(),
            ...$this->heatingAndGas(),
            ...$this->electrical(),
            ...$this->equipment(),
            ...$this->waterAndOutdoor(),
            ...$this->services(),
            ...$this->administration(),
            ...$this->payments(),
            ...$this->seasonal(),
        ];
    }

    /**
     * Požární ochrana — hasicí přístroje, hydranty, nouzové osvětlení, EPS,
     * spalinová cesta.
     *
     * @return list<CatalogEntry>
     */
    private function fireSafety(): array
    {
        $c = TaskCategory::REVISION;
        $i = TaskRecurrence::INTERVAL;

        return [
            new CatalogEntry(
                'fire_extinguisher_check',
                'Kontrola hasicích přístrojů',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se každého hasicího přístroje v objektu.',
                'vyhl. 246/2001 Sb.'
            ),
            new CatalogEntry(
                'fire_extinguisher_pressure',
                'Periodická zkouška hasicích přístrojů',
                $c,
                $i,
                5,
                TaskIntervalUnit::YEAR,
                'Tlaková zkouška přístroje. U vodních a pěnových přístrojů je lhůta 3 roky.',
                'vyhl. 246/2001 Sb.'
            ),
            new CatalogEntry(
                'hydrant_check',
                'Kontrola hydrantu',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se objektů s vnitřním požárním hydrantem.',
                'ČSN 73 0873'
            ),
            new CatalogEntry(
                'emergency_lighting',
                'Kontrola nouzového osvětlení',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se objektů s nouzovým osvětlením únikových cest.',
                'vyhl. 246/2001 Sb.'
            ),
            new CatalogEntry(
                'fire_alarm',
                'Zkouška elektrické požární signalizace',
                $c,
                $i,
                6,
                TaskIntervalUnit::MONTH,
                'Týká se objektů s instalovanou EPS.',
                'vyhl. 246/2001 Sb.'
            ),
            new CatalogEntry(
                'chimney_sweep',
                'Kontrola a čištění spalinové cesty',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se každého komína a kouřovodu od kotle, krbu nebo kamen.',
                'vyhl. 34/2016 Sb.'
            ),
        ];
    }

    /**
     * Vytápění a plyn.
     *
     * @return list<CatalogEntry>
     */
    private function heatingAndGas(): array
    {
        $c = TaskCategory::REVISION;
        $i = TaskRecurrence::INTERVAL;

        return [
            new CatalogEntry(
                'solid_fuel_boiler',
                'Kontrola technického stavu kotle na pevná paliva',
                $c,
                $i,
                3,
                TaskIntervalUnit::YEAR,
                'Týká se kotlů na pevná paliva o příkonu 10–300 kW napojených na teplovodní soustavu.',
                'z. 201/2012 Sb.'
            ),
            new CatalogEntry(
                'gas_appliance_check',
                'Kontrola plynového zařízení',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se domovního rozvodu plynu a spotřebičů.',
                'vyhl. 85/1978 Sb.'
            ),
            new CatalogEntry(
                'gas_revision',
                'Provozní revize plynového zařízení',
                $c,
                $i,
                3,
                TaskIntervalUnit::YEAR,
                'Širší revize plynového zařízení nad rámec roční kontroly.',
                'vyhl. 85/1978 Sb.'
            ),
        ];
    }

    /**
     * Elektro a ochrana před bleskem.
     *
     * @return list<CatalogEntry>
     */
    private function electrical(): array
    {
        $c = TaskCategory::REVISION;
        $i = TaskRecurrence::INTERVAL;

        return [
            new CatalogEntry(
                'electrical_installation',
                'Revize elektroinstalace',
                $c,
                $i,
                5,
                TaskIntervalUnit::YEAR,
                'Ve vlhkých prostorách a prostorách s vyšším rizikem je lhůta kratší.',
                'ČSN 33 1500'
            ),
            new CatalogEntry(
                'electrical_appliances',
                'Revize elektrických spotřebičů a nářadí',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se spotřebičů, které používají hosté — rychlovarná konvice, fén, žehlička.',
                'ČSN 33 1600 ed. 2'
            ),
            new CatalogEntry(
                'lightning_visual',
                'Vizuální kontrola hromosvodu',
                $c,
                $i,
                2,
                TaskIntervalUnit::YEAR,
                'Týká se objektů s hromosvodem.',
                'ČSN EN 62305-3'
            ),
            new CatalogEntry(
                'lightning_revision',
                'Revize hromosvodu',
                $c,
                $i,
                4,
                TaskIntervalUnit::YEAR,
                'Úplná revize soustavy ochrany před bleskem.',
                'ČSN EN 62305-3'
            ),
        ];
    }

    /**
     * Technická zařízení budovy — tlakové nádoby, chlazení, výtah, hlásiče.
     *
     * @return list<CatalogEntry>
     */
    private function equipment(): array
    {
        $c = TaskCategory::REVISION;
        $i = TaskRecurrence::INTERVAL;

        return [
            new CatalogEntry(
                'pressure_vessel',
                'Provozní revize tlakové nádoby',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se bojleru, expanzní nádoby a tlakové nádoby domácí vodárny.',
                'ČSN 69 0012'
            ),
            new CatalogEntry(
                'pressure_vessel_inner',
                'Vnitřní revize tlakové nádoby',
                $c,
                $i,
                5,
                TaskIntervalUnit::YEAR,
                'Vnitřní prohlídka nádoby nad rámec roční provozní revize.',
                'ČSN 69 0012'
            ),
            new CatalogEntry(
                'refrigerant_leak',
                'Kontrola těsnosti klimatizace a tepelného čerpadla',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se zařízení s náplní fluorovaných plynů nad 5 tun ekvivalentu CO₂; podle náplně může být lhůta kratší.',
                'nař. EU 517/2014'
            ),
        ];
    }

    /**
     * Voda z vlastního zdroje a venkovní prostory pro hosty.
     *
     * @return list<CatalogEntry>
     */
    private function waterAndOutdoor(): array
    {
        $c = TaskCategory::REVISION;
        $i = TaskRecurrence::INTERVAL;

        return [
            new CatalogEntry(
                'well_water_analysis',
                'Rozbor vody z vlastního zdroje',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se ubytování zásobovaného z vlastní studny nebo vrtu, včetně prohlídky studny.',
                'z. 258/2000 Sb.'
            ),
            new CatalogEntry(
                'playground_inspection',
                'Roční kontrola dětského hřiště',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se herních prvků, trampolíny a prolézaček pro hosty.',
                'ČSN EN 1176'
            ),
            new CatalogEntry(
                'elevator_inspection',
                'Odborná prohlídka výtahu',
                $c,
                $i,
                3,
                TaskIntervalUnit::MONTH,
                'Týká se objektů s výtahem; provozní prohlídka se dělá po 14 dnech.',
                'ČSN 27 4002'
            ),
            new CatalogEntry(
                'smoke_detector',
                'Kontrola hlásičů kouře a oxidu uhelnatého',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Funkce hlásičů a výměna baterií.',
                null
            ),
        ];
    }

    /** @return list<CatalogEntry> */
    private function services(): array
    {
        $c = TaskCategory::SERVICE;
        $i = TaskRecurrence::INTERVAL;

        return [
            new CatalogEntry(
                'gas_boiler_service',
                'Servis plynového kotle',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Servis podle pokynů výrobce; bez něj obvykle zaniká záruka.',
                null
            ),
            new CatalogEntry(
                'ventilation_filters',
                'Výměna filtrů rekuperace',
                $c,
                $i,
                6,
                TaskIntervalUnit::MONTH,
                'Týká se objektů s řízeným větráním.',
                null
            ),
            new CatalogEntry(
                'pest_control',
                'Deratizace a dezinsekce',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Ochranná dezinsekce a deratizace objektu.',
                null
            ),
            new CatalogEntry(
                'mattress_check',
                'Kontrola matrací, povlečení a ručníků',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Stav vybavení, které hosté používají nejvíc.',
                null
            ),
            new CatalogEntry(
                'first_aid_kit',
                'Kontrola lékárničky a expirací',
                $c,
                $i,
                1,
                TaskIntervalUnit::YEAR,
                'Obsah lékárničky a data spotřeby.',
                null
            ),
        ];
    }

    /** @return list<CatalogEntry> */
    private function administration(): array
    {
        $c = TaskCategory::ADMIN;
        $f = TaskRecurrence::FIXED_DATE;

        return [
            new CatalogEntry(
                'stay_fee_report',
                'Odvod poplatku z pobytu obci',
                $c,
                $f,
                3,
                TaskIntervalUnit::MONTH,
                'Termín a četnost stanoví obecně závazná vyhláška obce — uprav podle své obce.',
                null
            ),
            new CatalogEntry(
                'income_tax_return',
                'Daňové přiznání k dani z příjmů',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Papírově do 1. dubna, elektronicky do 2. května, s poradcem do 1. července.',
                null
            ),
            new CatalogEntry(
                'social_health_summary',
                'Přehledy pro ČSSZ a zdravotní pojišťovnu',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Roční přehledy OSVČ o příjmech a výdajích.',
                null
            ),
            new CatalogEntry(
                'guest_book_retention',
                'Skartace evidenční knihy hostů',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Zápisy se uchovávají 6 let od posledního zápisu, pak se skartují.',
                'z. 326/1999 Sb.'
            ),
            new CatalogEntry(
                'operating_rules',
                'Aktualizace provozního řádu',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Provozní řád ubytovacího zařízení a jeho soulad se skutečným provozem.',
                'z. 258/2000 Sb.'
            ),
            new CatalogEntry(
                'season_pricing',
                'Ceník na příští sezónu',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Nastavení cen a jejich promítnutí do webu a portálů.',
                null
            ),
        ];
    }

    /** @return list<CatalogEntry> */
    private function payments(): array
    {
        $c = TaskCategory::PAYMENT;
        $f = TaskRecurrence::FIXED_DATE;

        return [
            new CatalogEntry(
                'property_insurance',
                'Pojištění nemovitosti a odpovědnosti',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Výročí smlouvy — platba a kontrola, jestli pojistná částka odpovídá.',
                null
            ),
            new CatalogEntry(
                'domain_hosting',
                'Prodloužení domény a hostingu',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Expirace domény webu a hostingu.',
                null
            ),
            new CatalogEntry(
                'collective_licences',
                'Licence OSA a Intergram',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Týká se ubytování s televizí nebo rádiem na pokojích.',
                'z. 121/2000 Sb.'
            ),
            new CatalogEntry(
                'broadcast_fee',
                'Poplatky za televizní a rozhlasové přijímače',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Počet přijímačů v ubytování a jejich nahlášení.',
                null
            ),
        ];
    }

    /** @return list<CatalogEntry> */
    private function seasonal(): array
    {
        $c = TaskCategory::SEASONAL;
        $f = TaskRecurrence::FIXED_DATE;

        return [
            new CatalogEntry(
                'winterization',
                'Zazimování vody a venkovních rozvodů',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Vypuštění venkovních rozvodů před prvními mrazy.',
                null
            ),
            new CatalogEntry(
                'spring_opening',
                'Jarní zprovoznění vody a zahrady',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Napuštění rozvodů, kontrola po zimě, příprava venkovních prostor.',
                null
            ),
            new CatalogEntry(
                'pool_service',
                'Servis bazénu nebo vířivky',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Sezónní spuštění, výměna náplně a filtrace.',
                null
            ),
            new CatalogEntry(
                'gutters_cleaning',
                'Čištění okapů a kontrola střechy',
                $c,
                $f,
                1,
                TaskIntervalUnit::YEAR,
                'Po opadání listí, před zimou.',
                null
            ),
        ];
    }
}
