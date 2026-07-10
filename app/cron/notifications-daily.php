<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/*
 * Cron wrapper (1× denně): nejdřív přepočet DPH (reverse charge) na rezervacích
 * s provizí, ať měsíční přehled a připomínka pracují s aktuálním základem i kurzem
 * ČNB. Pak upozornění na vznik identifikované osoby (jednorázově po první provizi),
 * DPH připomínka (sama se zkratuje mimo ~20. den) a nakonec denní souhrn — tak jde
 * i DPH připomínka v režimu „souhrn" ven týž den. Všechny kroky jsou idempotentní;
 * každý běží nezávisle a ven propadne první nenulový návratový kód (pro monitoring).
 */

$run = require __DIR__ . '/_kernel.php';

$recalculate = $run('app:vat:recalculate');
$identifiedPerson = $run('app:tax:identified-person-remind');
$remind = $run('app:vat:remind');
$digest = $run('app:notifications:digest');

foreach ([$recalculate, $identifiedPerson, $remind, $digest] as $code) {
    if ($code !== 0) {
        exit($code);
    }
}

exit(0);
