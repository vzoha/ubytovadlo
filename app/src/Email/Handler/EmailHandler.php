<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Handler;

use App\Email\EmailMessage;
use App\Entity\EmailLog;
use App\Enum\ConnectorType;

/**
 * Jeden typ příchozího e-mailu (Airbnb rezervace/výplata, Booking trigger/faktura,
 * platba z banky). Handler si sám rozhodne, jestli e-mail patří jemu (`supports`),
 * ke kterému konektoru se váže (`connectorType`) a zpracuje ho (`handle`) — parsuje,
 * promítne do domény a označí výsledek do `EmailLog`.
 *
 * `EmailDispatcher` jen iteruje registrované handlery, takže nový typ e-mailu
 * znamená přidat handler, ne zasahovat do dispatcheru.
 */
interface EmailHandler
{
    public function supports(EmailMessage $email): bool;

    /** Konektor, ke kterému tento typ e-mailu patří (pro kontrolu zapnutí a evidenci aktivity). */
    public function connectorType(): ConnectorType;

    public function handle(EmailMessage $email, EmailLog $log): void;
}
