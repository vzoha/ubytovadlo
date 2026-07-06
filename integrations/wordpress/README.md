# Ubytovadlo — WordPress integrace

## Okamžitý import z webu (MotoPress webhook)

`ubytovadlo-motopress-webhook.php` zajistí, že rezervace z vlastního webu
(WordPress + MotoPress Hotel Booking) naskočí do Ubytovadla **hned**, ne až
s pravidelnou kontrolou přes REST API.

### Jak to funguje

Při vytvoření rezervace (`mphb_booking_placed`) i jejím potvrzení
(`mphb_booking_confirmed`) MotoPress spustí akci, na kterou plugin zavolá webhook
adresu Ubytovadla a pošle jen **ID rezervace**. Ubytovadlo si detail dotáhne
z REST API a rezervaci upsertne stejnou cestou jako pravidelný import — takže se
s ním nebije (je idempotentní, dvojí ťuknutí nezaloží duplicitu) a slouží jako
záchranná síť, kdyby ťuknutí nedorazilo.

Žádná logika ani údaje hosta nejsou v pluginu — je to jen forwarder.

### Nasazení

1. Zkopírujte `ubytovadlo-motopress-webhook.php` do `wp-content/mu-plugins/`
   (must-use plugins se načtou samy, bez aktivace v adminu). Adresář `mu-plugins`
   případně vytvořte.
2. V Ubytovadle otevřete **Nastavení → Připojení → Okamžitý import z webu**
   a zkopírujte adresu.
3. Do `wp-config.php` (nad `/* That's all, stop editing! */`) doplňte:

   ```php
   define('UBYTOVADLO_WEBHOOK_URL', 'https://app.priklad.cz/webhook/motopress/…token…');
   ```

4. Zkuste testovací rezervaci — do pár vteřin se objeví v Ubytovadle.

### Bezpečnost

Adresa obsahuje tajný token (kdo ji zná, může spustit import). Držte ji jen v
`wp-config.php`, nesdílejte. Když unikne, v Ubytovadle vygenerujte novou adresu
(tlačítko na téže stránce) a nahraďte ji tady.
