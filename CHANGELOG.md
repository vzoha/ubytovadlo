# Changelog

Všechny podstatné změny v tomto projektu se zaznamenávají sem.
Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verzování dle [SemVer](https://semver.org/lang/cs/).

## [Unreleased]

### Přidáno

- **Zrušení rezervace.** Na detailu rezervace je tlačítko *Zrušit rezervaci*
  a u zrušené *Obnovit rezervaci*. Storno zavře naplánované akce, takže hostovi
  zrušeného pobytu už žádná zpráva neodejde, a přepočítá příjem — zůstanou jen
  reálně přijaté peníze, odhad výplaty zmizí. Termín se uvolní v přehledu,
  kalendáři i v iCal feedu pro prodejní portály. U rezervací z vlastního webu
  jde storno zaškrtnutím propsat i do MotoPressu, aby se termín vrátil do
  prodeje; u Bookingu a Airbnb dialog vede nejdřív do extranetu, kde o stornu
  rozhoduje portál. Obnovení vrátí stav podle kalendáře a znovu naplánuje akce,
  které storno zavřelo.

- **Odkaz z rezervace do extranetu.** Rezervace z Bookingu a Airbnb mají v hlavičce
  tlačítko *Otevřít v Booking.com* / *Otevřít v Airbnb* — vede rovnou na stránku
  té rezervace v extranetu portálu. Airbnb stačí potvrzující kód; ID ubytování pro
  Booking se vyplní samo z e-mailu o nové rezervaci a je i v **Nastavení →
  Připojení**.

- **Stav rezervace se posouvá podle kalendáře.** V den příjezdu rezervace přejde
  na *Probíhá*, den po odjezdu na *Dokončeno*. Běží v cronu jako součást plánování
  akcí; storno a *Doplnit údaje* zůstávají, dokud je nezmění člověk nebo import.

- **Zpráva před odjezdem.** Nový druh zprávy hostům — odejde den před odjezdem
  v 17:00 a připomene čas odchodu, klíče a co nechat v pořádku. Text je
  předvyplněný česky i anglicky, časování i režim se nastavují v **Nastavení →
  Šablony zpráv** jako u ostatních zpráv; ve výchozím stavu čeká na časové ose
  rezervace na odeslání tlačítkem. Rezervace bez data odjezdu ji nedostane.

- **Kalendář obsazenosti.** V menu *Kalendář* ukazuje měsíc ve dvou pohledech:
  **osa** (řádek na jednotku, sloupec na den) a **měsíc** (klasická mřížka týdnů).
  Pás rezervace nese jméno hosta a barvu prodejního kanálu, začíná odpoledne
  příjezdu a končí dopoledne odjezdu — den, kdy jeden host odjíždí a druhý
  přijíždí, je tak vidět jako obsazený z obou půlek. Překrývající se rezervace
  leží nad sebou s červeným rámem a hlášením o dvojím obsazení, rezervace bez
  údajů hosta má šrafovaný pás. Pod jednotkami jsou řádky *Úklid* (na den odjezdu)
  a *Termíny* (hlídané revize a servis). Klik na pás otevře rezervaci. Na přehledu
  je stejná osa pro nejbližší dva týdny.

- **Jazyk zpráv jde u rezervace zvolit ručně.** V údajích hosta je pole
  *Jazyk zpráv*: ve výchozím stavu se jazyk odvodí ze země v adrese, volbou
  *Čeština* nebo *Angličtina* se přebije. Na detailu rezervace je u hosta vidět,
  který jazyk platí a jestli plyne ze země, nebo z ruční volby.

- **Zprávy hostům umí anglicky.** Každá šablona má záložky *Čeština* a *Angličtina*;
  anglická verze je předvyplněná hotovým textem, takže funguje i bez úprav. Hosté
  s jinou zemí v adrese než Česko nebo Slovensko dostanou anglickou verzi, ostatní
  českou. Režim odesílání a časování na ose rezervace drží česká verze — nastavují
  se jednou pro obě jazykové mutace. Testovací odeslání a náhled pracují s jazykem,
  který je právě otevřený.

- **Průvodce nastavením vede k údajům ubytovacího zařízení.** Mezi krokem
  *Dodavatel* a *Připojení* přibyl krok **Ubytování** — název objektu, adresa,
  kontaktní spojení a identifikátory od cizinecké policie (IDUB, kód zařízení),
  ze kterých se skládá hlavička hlášení na Ubyport. Vyplnit je jde dál i
  samostatně v **Nastavení → Ubytovací zařízení**.

- **Připravené texty rychlých zpráv.** Formulář nové rychlé zprávy nabízí výběr
  *Začít ze vzoru* — uvítání, výzva k online check-inu s odkazem na check-in té
  rezervace, pokyny k příjezdu a poděkování po pobytu. Vzor název i text jen předvyplní, dál je
  zpráva samostatná. Dokončením průvodce nastavením dostane instance stejnou sadu
  rovnou do seznamu; příkaz `app:quick-messages:seed` ji naplní i jindy, pokud je
  seznam prázdný.

- **Ubytování jako vlastní sekce nastavení.** Název objektu, jeho adresa a kontakt
  pro hosty mají domov v sekci *Ubytování* — odtud je berou zprávy hostům i hlášení
  na Ubyport. Sekce *Ubyport* drží identifikátory od cizinecké policie (IDUB, kód
  zařízení) a údaje objektu jen ukazuje s odkazem na jejich úpravu. Fakturační adresa
  dodavatele zůstává ve *Fakturaci*; obě stránky říkají, čím se od sebe liší.

- **Kanály a platby jako vlastní sekce nastavení.** Každé napojení má kartu se stavem,
  přepínačem, testem spojení, adresou iCal feedu i vlastním nastavením a ukládá se
  samostatně; ve výchozím stavu je sbalená a stav nese hlavička.
  Portály jsou pod nadpisem *Prodejní kanály*, banka pod *Platby*. Vypisují se jen
  napojení, která instance používá — zbytek nabídne tlačítko *Přidat napojení*.
  Seznam uzavírá adresa vlastního kalendáře pro import do portálů; obě strany
  výměny obsazenosti se jmenují podle směru (*Kalendář portálu → k nám* a
  *Náš kalendář → do portálů*) a karta kanálu nabízí naši adresu ke zkopírování
  rovnou u pole pro feed portálu.
  Sekce *Připojení* drží přístupy k poště, tedy příchozí schránku a odchozí server.

- **Návod na zabezpečení zpráv v Booking.com.** Karta kanálu *Booking.com* říká,
  co v extranetu schválit, aby zprávy hostům procházely: adresu odesílatele mezi
  schválené adresy a doménu aplikace mezi schválené odkazy. Obě hodnoty jsou vypsané
  tak, jak je má daná instance nastavené, včetně upozornění na přepínače blokující
  odkazy a e-maily.

- **Zprávy k odeslání na přehledu.** Karta shrnuje zprávy hostům, kterým nadešel
  čas a čekají na ruční odeslání — s termínem, jménem hosta, štítkem *po termínu*
  a u hosta bez e-mailu štítkem chatu portálu. Proklik vede na rezervaci.

- **Zpráva do chatu přímo z časové osy.** U rezervace z portálu bez e-mailu hosta
  nabídne naplánovaná zpráva tlačítko *Zpráva do chatu*, které otevře okno
  s připravenými texty ke zkopírování. Odeslání e-mailem se nabízí tam, kde
  je na co odeslat.

- **Vstup do check-inu kódem rezervace.** Adresa `/checkin` otevře formulář, kde
  host zadá kód rezervace z potvrzení a své příjmení, a dostane se tím na svou
  check-in stránku. Adresa je pro všechny hosty stejná, takže se dá napsat do
  šablony zprávy vedle kódu — hodí se do chatu portálu, kam odkaz s tokenem
  posílat nechcete. Sedět musí obojí, kód i příjmení, rezervace musí být kolem
  termínu pobytu a počet pokusů z jedné adresy je omezený; neúspěch nerozliší,
  co bylo špatně. Šablony zpráv mají k tomu proměnné `checkin_lookup_url`
  a `checkin_code`.

- **Zpráva do chatu portálu.** Na detailu rezervace otevře tlačítko *Zpráva do chatu*
  okno s rychlými zprávami vyplněnými údaji té rezervace — včetně odkazu na online
  check-in. Text jde před zkopírováním upravit, tlačítko *Zkopírovat* ho dá do
  schránky a odkaz vedle vede rovnou do konverzace v Bookingu či Airbnb. Nabízí se
  i u hostů bez telefonu a e-mailu, tedy tam, kde komunikace běží jen přes chat portálu.

### Opraveno

- **Krok fakturace v check-inu mluví o vystavení faktury.** Host se dozví, že fakturu
  za pobyt vystavíme na jméno a adresu, které vyplní, a že u firmy patří do formuláře
  i její údaje.

- **Adresa ubytování ve zprávách drží část obce a mezeru v PSČ.** Na vesnici bez ulic
  nese adresu část obce (*Lniště 30, 374 01 Slavče*), ve městě se uvádí vedle ulice
  (*Horova 12/3, Žabovřesky, 616 00 Brno*). Číslo popisné a orientační odděluje lomítko.

- **Upozornění „čeká na doplnění údajů" říká, co konkrétně chybí.** Když není
  známé jméno hosta, odkáže na extranet portálu, který termín drží. Když chybí
  jen fakturační adresa, vede u Bookingu do detailu rezervace v extranetu a
  u Airbnb k odkazu na online check-in, protože Airbnb adresu hosta neposkytuje.

- **Ekonomika zrušené rezervace počítá jen to, co se opravdu stalo.** Příjmem
  jsou peníze, které dorazily (nevrácená záloha, storno poplatek), ne cena
  pobytu. Úklid, elektřina ani rekreační poplatek se k neuskutečněnému pobytu
  neúčtují a provize portálu se počítá jen tehdy, když ji portál skutečně
  vyúčtoval. Karta *Ekonomika* to označuje štítkem a zisk na noc u zrušeného
  pobytu neukazuje.

### Změněno

- **Po přihlášení na nenastavené instanci otevře správce průvodce nastavením.**
  Nabízí se, dokud zbývá co nastavit a dokud ho správce tlačítkem *Hotovo*
  neuzavře; pak se spouští z karty na přehledu. Uklízečka ani provozní správce
  průvodce nedostanou.

- **Krok Připojení vrací zpět do průvodce.** Odkaz na vyplnění přístupů se po
  uložení vrátí na krok průvodce, ne na stránku nastavení. Za hotový se krok
  považuje po vyplnění SMTP serveru — bez něj hostovi neodejde ani zpráva, ani
  notifikace.

- **Aplikace se ovládá i z telefonu.** Přehledy s mnoha sloupci — rezervace, účty,
  DPH, termíny — se na úzké obrazovce zobrazují jako karty, kde má každý údaj svůj
  popisek; na širší obrazovce zůstávají tabulkou. Filtry stavů se zalomí do řádků,
  data a částky se nelámou uprostřed a stránka se nikam neposouvá do strany.
  Design manuál drží pravidla pro šířku 360 px. Karty na nástěnce drží stejné
  chování — odznak se zalomí pod nadpis, název úkonu i popis dostanou plnou
  šířku a odkaz na detail zůstane vcelku.

- **Prázdný přehled ukazuje jen hlášku.** Tabulka bez dat — úklid, elektřina,
  Ubyport, ekonomika, termíny — vypíše důvod, proč je prázdná, bez hlavičky
  sloupců nad ničím. Hlášky na nástěnce mají odsazení ze všech stran.

- **Částky se všude vypisují se symbolem měny.** Koruny jako `Kč`, eura jako `€`,
  dolary, libry a zloté odpovídajícím znakem; měna bez známého symbolu se vypíše
  ISO kódem. Platí pro seznam a detail rezervace, přehled na nástěnce, faktury
  i podklady k DPH.

- **Obsazenost z iCal feedů se stahuje spolu s rezervacemi z webu.** Cron wrapper
  `app/cron/channels-sync.php` obsluhuje kanály, na které se ptáme sami — MotoPress
  REST i iCal feedy Booking, Airbnb, eChalupy a CS chalupy. Hostingům se stropem na
  počet cron úloh tak stačí čtyři čtvrthodinové úlohy a jedna denní.

## [0.11.0] — 2026-08-24

### Přidáno

- **Termíny.** Nová sekce **Termíny** hlídá opakované úkony ubytovatele — revize
  a kontroly, servis, administrativu, platby i sezónní práce. U každého úkonu se
  drží lhůta, kdo ho dělá a podle jakého předpisu, a seznam ukazuje, co je po
  termínu, co se blíží a co je v pořádku. Zápis provedení posune termín podle
  zvoleného opakování (od posledního provedení, pevné datum v kalendáři, nebo
  jednorázově), uloží protokol k pozdějšímu dohledání a na přání zaúčtuje cenu
  jako výdaj v cashflow. Katalog obvyklých termínů — od kontroly hasicích
  přístrojů a spalinové cesty přes revizi elektroinstalace a rozbor vody až po
  pojistku, poplatek obci nebo zazimování — nabídne úkon i s lhůtou a předpisem;
  stačí vybrat, co se objektu týká. Blížící se a zmeškané termíny jsou na
  přehledu a chodí na ně e-mailové upozornění (denní cron `app:tasks:remind`,
  režim doručení se nastavuje v **Nastavení → Notifikace**).
- **Cena v korunách u rezervací v cizí měně.** V seznamu rezervací je pod cenou
  v cizí měně částka v korunách. Přepočítává se kurzem z vystavené faktury, jinak
  kurzem ČNB uloženým k rezervaci; dokud rezervace svůj kurz nemá, ukáže se
  orientační přepočet dnešním kurzem ČNB označený „≈". Najetím myší se zobrazí,
  jaký kurz a ke kterému dni se použil.
- **Náhled zprávy před odesláním.** Na časové ose rezervace je u každé
  naplánované zprávy hostovi tlačítko *Náhled*: ukáže příjemce, předmět i celý
  e-mail s daty té rezervace — přesně v podobě, v jaké hostovi odejde.
- **Evidenční kniha hostů.** V **Ekonomika → Kniha hostů** je za každý rok přehled
  ubytovaných jako podklad k poplatku z pobytu (§ 3g zákona o místních poplatcích):
  jméno, datum narození, občanství, druh a číslo dokladu, adresa bydliště a doba
  pobytu. Obsahuje hosty, kteří potvrdili údaje v online check-inu, a jde stáhnout
  jako CSV.
- **Přepínač „Evidovat i české hosty".** V **Nastavení → Obecné** se volí, zda
  online check-in sbírá doklad a adresu i od českých hostů (pro evidenční knihu),
  nebo je vyplňují jen cizinci (pro Ubyport). Ve výchozím stavu zapnuto.
- **Výběr jazyka na online check-inu.** Host si stránky check-inu přepne v
  nabídce vlajek mezi češtinou, angličtinou, němčinou, francouzštinou, italštinou
  a polštinou; každý jazyk je uvedený svým vlastním názvem. Při první návštěvě se
  jazyk nabídne podle nastavení jeho prohlížeče. Přeložený je celý průvodce —
  výběr hosta, sken dokladu (včetně pokynů kamery a hlášek čtečky), fakturační
  formulář i děkovací stránka. Volba se drží po celou dobu vyplňování. Seznam
  zemí se zobrazuje v jazyce hosta (česky, jinak anglicky).
- **Zaokrouhlení faktury placené hotově.** Faktura se způsobem platby *hotově*
  vychází na celé koruny — rozdíl je na ní vidět samostatným řádkem
  *Zaokrouhlení*. Řádek je bez DPH, takže rekapitulace daně zůstává na haléře
  přesná. Přepnutí faktury zpět na převod řádek odebere a částka k úhradě i QR
  kód odpovídají přesné ceně.

### Změněno

- **Seznam rezervací ukazuje aktivní rezervace.** Výchozí pohled *Aktivní* drží
  zrušené rezervace stranou; zobrazí se pod filtrem *Zrušeno*.
- **Aplikace běží na MySQL i MariaDB.** Obě databáze jsou pokryté automatickými
  testy, takže sdílený hosting s MariaDB je plnohodnotná varianta.
- **Online check-in má dvě úrovně.** Každý host projde stejným krokem do evidenční
  knihy — jméno, datum narození, druh a číslo dokladu, adresa bydliště —
  a cizinci navíc vyplní státní občanství a vízum pro hlášení cizinecké policii
  (Ubyport). Sken dokladu (foto i živá kamera) předvyplní údaje pro české i
  zahraniční hosty; obraz dokladu zůstává v prohlížeči, ukládá se jen text.
- **Formulářová pole používají sémantický typ.** E-mail, telefon a web v nastavení
  dodavatele i připojení jednají jako nativní pole (`e-mail`, `telefon`, `URL`) —
  na mobilu naskočí odpovídající klávesnice a prohlížeč hlídá formát. U číselných
  polí (porty, poplatky, ceny úklidu, splatnost zálohy, pořadové číslo faktury)
  drží prohlížeč povolený rozsah přímo ve vstupu. Hodina odeslání u zpráv hostům
  se zadává výběrem času (`HH:MM`).

### Zabezpečení

- Aktualizace `league/commonmark` na 2.9 — verze bez známých zranitelností pro
  odepření služby při zpracování Markdownu v šablonách zpráv.

## [0.10.0] — 2026-07-17

### Přidáno

- **Nastavitelné časování a režim zpráv hostům.** U každé plánované zprávy (žádost
  o zálohu, před příjezdem, po pobytu, připomínka doplatku) se v **Nastavení →
  Šablony zpráv** volí, **kdy** odejde — buď v přesný čas té události (objednávky,
  příjezdu nebo odjezdu), nebo pár dní před/po ní v konkrétní hodinu. Každá
  zpráva má jeden ze tří **režimů**: *Automaticky* (odešle se sama v naplánovaný
  čas), *Ručně* (objeví se na časové ose rezervace a odešleš ji tlačítkem
  **Odeslat**), *Vypnuto* (na osu se vůbec nezaloží). U konkrétní rezervace lze
  zprávu na časové ose kdykoli odložit, zrušit nebo odeslat dřív.
- **Rychlý kontakt na hosta z detailu rezervace.** U karty Host jsou tlačítka
  **Volat**, **SMS**, **WhatsApp** a **E-mail** — otevřou příslušnou aplikaci
  s předvyplněným číslem (nebo adresou). Telefon se zobrazuje v přehledném národním
  tvaru (`776 123 456`). WhatsApp a SMS ve výchozím stavu otevřou prázdnou zprávu;
  přes rozbalovátko lze zprávu **předvyplnit rychlou zprávou** s dosazenými údaji
  rezervace.
- **Rychlé zprávy pro SMS a WhatsApp.** V **Nastavení → Rychlé zprávy** si založíš
  vlastní krátké texty (např. „Uvítání", „Kód dveří", „Poděkování") s proměnnými
  jako `{{ guest_first_name_vocative }}`. Zobrazí se v nabídce u tlačítek SMS a
  WhatsApp na detailu rezervace a jejich pořadí lze měnit.
- **Telefon hosta se ukládá v jednotném tvaru.** Zadané číslo se sjednotí na
  mezinárodní formát E.164 (`+420776123456`) bez ohledu na to, jak ho kdo napíše —
  s mezerami, pomlčkami, s předvolbou i bez ní. Díky tomu půjde nad číslem spolehlivě
  postavit odkazy na volání, SMS i WhatsApp.
- **Podklad rekreačního poplatku pro obec.** V **Ekonomice** je pod tlačítkem
  **Rekreační poplatek** roční přehled pobytů s počtem poplatníků (dospělých; děti
  jsou osvobozené), nocí a částkou k odvodu — zvlášť za uskutečněné pobyty a celkem
  včetně plánovaných. Přehled jde stáhnout jako **CSV** pro účetní nebo obec.
- **Sazba rekreačního poplatku v nastavení.** V **Nastavení → Poplatky** se zadá
  sazba za dospělou osobu a noc; aplikace ji použije u každé rezervace.
- **Upozornění na vznik identifikované osoby.** Jakmile neplátci dorazí první provize
  z OTA (přeshraniční přijatá služba z EU), aplikace pošle e-mail, že se stal
  **identifikovanou osobou** podle §6h ZDPH a má **15 dnů** na přihlášku k registraci.
- **Návod, kde vzít adresu kalendáře (iCal) portálu.** Průvodce i stránka Připojení
  ukazují u každého kanálu, kde v jeho administraci najít odkaz na export kalendáře —
  Booking.com, Airbnb, e-chalupy.cz a CS chalupy. U e-chalupy.cz zdůrazní, že se
  vkládá export „s podrobnostmi" (s privátním klíčem), ne „základní".

### Změněno

- **Údaje hosta jako hodnotové objekty.** Adresa (`Address`), firemní údaje
  (`BillingIdentity`) a kontakt (`GuestContact`) jsou samostatné neměnné celky
  s vlastní normalizací — prázdné pole je vždy `null`, země ISO kódem velkými
  písmeny, telefon v E.164. Firemní údaje sdílí rezervace i faktura, takže
  snapshot odběratele na faktuře je jedno přiřazení. Formuláře je skládají přes
  `AddressType`, `BillingIdentityType` a `GuestContactType`.
- **Zpracování příchozích e-mailů přes handlery.** Každý typ e-mailu (Airbnb
  rezervace a výplata, Booking trigger a měsíční faktura, platba z banky) má
  vlastní handler, který rozhodne, jestli e-mail patří jemu, ke kterému konektoru
  se váže, a promítne ho do domény. `EmailDispatcher` jen najde odpovídající
  handler a deleguje — nový typ e-mailu znamená přidat handler.
- **Sdílené utility pro parsování e-mailů.** Čištění bílých znaků z e-mailů
  (`EmailText::normalizeWhitespace`) a genitiv názvů měsíců
  (`CzechCalendar::genitiveMonths`) mají jedno místo, ze kterého čerpají parsery
  Airbnb, Booking i ČS notifikací.
- **Rozpoznání provizní OTA na jednom místě.** `Channel::isOta()` říká, jestli
  je kanál provizní OTA (Booking, Airbnb) — řídí `needs_details` tok, provizi a
  reverse-charge DPH. eChalupy a CS chalupy sem nepatří, jsou to jen iCal feedy
  obsazenosti.
- **Práce s peněžní částkou má jedno místo.** `App\Formatting\Money` sjednocuje
  převod částky na kanonický decimal tvar pro uložení (`normalize`), převod
  uživatelského vstupu „1 234,50" na číslo (`parse`), symbol měny (`symbol` —
  CZK jako „Kč") i zobrazení (`format`). Fakturace, DPH, cashflow, MotoPress i
  parsování e-mailů částky odvozují odsud, takže se všude počítají a zobrazují
  stejně.
- **Jednotný vizuální jazyk tlačítek a odkazů.** Z každého ovládacího prvku je
  na první pohled poznat, co udělá: **modré tlačítko** mění data (plné = hlavní
  akce, orámované = vedlejší), s ikonou `+` pro nový záznam a `✎` pro úpravu v
  okně; **šedé tlačítko** je neutrální (filtr, export, kopírovat, náhled);
  **červené `×`** maže; **zelené** potvrzuje stav (zaplaceno, hotovo); a **odkaz
  se šipkou `→`** přejde na jinou stránku (`↗` na externí). Uplatněno napříč
  přehledy, detailem rezervace, fakturací, účty, ekonomikou, DPH, nastavením i
  check-inem.
- **Jednotná navigace zpět a nadpisy.** Odkaz zpět je vždy nad nadpisem a
  pojmenovaný podle cíle (`← Rezervace`, `← Účty`, `← Ekonomika`); přehledové
  stránky dostupné z hlavního menu (Elektřina, Úklid, Ubyport) tlačítko zpět
  nemají. Nadpisy stránek mají jednotnou velikost. Podstránkové formuláře nesou
  dvojici **Uložit** a **Zrušit**.
- **Zadávání a úpravy přes vyskakovací okno.** Formuláře pro přidání a úpravu
  záznamu se otevírají tlačítkem do dialogu, takže přehledové stránky vedou daty,
  ne formuláři. Platí pro **Účty** (výdaj, příjem, převod, uzávěrka, nový účet,
  úprava pohybu i účtu), **Elektřinu** (nový odečet), **detail rezervace**
  (poznámka, připomínka, úprava faktury), **Uživatele** (nový uživatel) a
  **Rychlé zprávy** (nová zpráva). Stránka Účty navíc vede zůstatky a tabulkou
  pohybů hned pod nadpisem; úprava a mazání pohybu zůstává v řádku tabulky.
- **Správa uživatelů je součástí Nastavení.** Uživatelé se otevírají jako záložka
  v sekci Nastavení (na adrese `/nastaveni/uzivatele`). Hlavní menu tak nese jen
  jednu položku pro administraci instance.
- **Přehled DPH se přepočítává sám.** Denní úloha přepočítá reverse charge z provizí
  (základ a kurz ČNB) na rezervacích s provizí, takže měsíční přehled i připomínka
  pracují s aktuálními čísly bez ručního spuštění.
- **Průvodce nastavením srozumitelnější pro netechnické uživatele.** Krok **E-maily**
  ukazuje živý náhled zprávy hostům, který se mění ihned při úpravě názvu, barev
  i patičky. Krok **Připojení** vysvětlí jednoduchými slovy, co se napojuje
  (automatizační schránka, prodejní kanály, případně vlastní web) a ukazuje aktuální
  stav připojení. Přibyl i návod, jak nasměrovat e-maily z Booking.com a Airbnb do
  automatizační schránky, včetně nastavení přeposílání pošty. Kroky lze procházet
  oběma směry — přes číslovanou lištu nahoře i tlačítkem Zpět.
- **Napojení na vlastní web (MotoPress) je upozaděné.** V Připojení i v průvodci je až
  za automatizační schránkou a odchozími e-maily a ve sbaleném bloku — vyžádá si ho jen
  ten, kdo prodává přes vlastní web s pluginem MotoPress.
- **Detail rezervace řazený podle důležitosti.** Nahoře jsou ve dvou sloupcích pobyt,
  host a peníze vlevo, check-in, ekonomika a provozní evidence (elektřina, úklid) vpravo.
  Fakturace je pod nimi na celou šířku, takže se tabulka faktur vejde v plné podobě;
  na úzkém okně se každý řádek složí do dvou řádků (číslo · typ … částka nahoře,
  stav · datum … akce dole). V pobytu přibyl počet nocí. Karty se stejně řadí i na mobilu.

### Opraveno

- **Úprava faktury srovná příjem na účtech.** Změna způsobu platby i data úhrady
  se hned promítne do přijatých plateb rezervace — platba hotově sedne na
  hotovostní účet, převod na bankovní, a datum příjmu odpovídá datu úhrady.
- **Příjem drží krok se změnou rezervace.** Storno z iCal feedu, přepis ceny nebo
  provize při synchronizaci s webem, import z Booking extranetu, údaje z Airbnb
  e-mailu i potvrzení rezervace přepočítají přijaté platby — zrušený pobyt zmizí
  z očekávaných příjmů a odhad výplaty odpovídá aktuální ceně.
- **Platba hosta se zapisuje na vybraný účet.** U ruční platby na detailu
  rezervace se volí účet, na který peníze dorazily (hotovost, banka).
- **Cena rezervace musí být číslo.** Ruční přidání rezervace ohlídá, že cena jde
  přečíst jako částka (`8500` i `8 500,50`) — nesrozumitelný zápis formulář
  odmítne s vysvětlením místo toho, aby rezervaci uložil s nulou.
- **Check-in pozná nedostupnou čtečku dokladu.** Když se knihovna pro čtení MRZ
  nenačte (offline, firewall, blokovaný obsah), sken to hostovi rovnou řekne a
  odkáže ho na ruční vyplnění; nabídka skenování kamerou se v takovém případě
  nezobrazí.
- **Splněná timeline akce se zavře hned, ne až v její termín.** Vystavení
  doplatkové faktury a připomínku doplatku uzavře přímo událost — jakmile je
  faktura vystavená nebo doplatek uhrazený, akce zmizí z otevřených, aniž by se
  čekalo na cron v jejím naplánovaném čase. Zaplacená rezervace tak nezobrazuje
  budoucí akce jako otevřené a hostovi se neposílá připomínka doplatku, který už
  zaplatil.
- **Pohyb mimo období účtu už tiše nezmizí ze stavu.** Stav účtu počítá pohyby
  od jeho počátečního data po dnešek. Když zapíšeš výdaj, příjem nebo převod
  s datem před založením účtu (nebo v budoucnosti), aplikace na to upozorní —
  takový pohyb se do stavu nezapočítá a víš proč. Formuláře pohybů mají navíc
  datum předvyplněné na dnešek.
- **Detail rezervace se vejde na mobil.** V úzké kartě se řádek tabulky faktur složí
  do dvou řádků (číslo a částka, pod tím stav a akce) místo přetékání stránky; v širší
  kartě zůstává plnou tabulkou. Řádky s údaji drží štítek i hodnotu na jednom řádku.
- **Ruční blokace kalendáře z Airbnb se neimportují jako rezervace.** Airbnb feed
  vedle rezervací obsahuje i termíny, které provozovatel ručně zavřel (SUMMARY
  „Airbnb (Not available)"). iCal import je přeskočí, takže nezakládají rezervaci
  čekající na doplnění hosta. U Bookingu a eChalup se importuje vše jako obsazenost.
- **Průvodce odškrtává jen skutečně vyplněné kroky.** Lišta kroků se řídí reálným
  stavem nastavení, ne pořadím — přeskočený krok zůstane neodškrtnutý, dokud ho
  nedoplníte.

## [0.9.0] — 2026-07-11

### Přidáno

- **Průvodce prvotním nastavením.** Na kartě „Co ještě nastavit" na přehledu je
  tlačítko **Spustit průvodce**, které provede aplikaci krok po kroku: název a adresa
  instance → dodavatel a daňový profil → připojení → e-maily → souhrn. Každý krok jde
  přeskočit a vrátit se k němu; závěrečný souhrn ukáže, co ještě zbývá dotáhnout,
  s odkazy na příslušná nastavení.
- **Kontrola obsazenosti — upozornění na dvojí prodej.** Když se dvě aktivní
  rezervace překrývají ve stejném termínu (typicky když se sesynchronizuje
  kolidující blok z jiného kanálu), dashboard nahoře ukáže **červenou kartu**
  s daným termínem a oběma rezervacemi, ať se dá hned zkontrolovat. Kontrolu jde
  spustit i z příkazové řádky (`app:occupancy:check`).

## [0.8.0] — 2026-07-11

### Přidáno

- **Daňový profil dodavatele a výstupní DPH na fakturách.** V **Nastavení → Fakturace**
  se u identity dodavatele volí daňový profil: **identifikovaná osoba** (výchozí),
  **plátce DPH**, nebo **neplátce DPH**. U **plátce** nesou faktury hostům výstupní DPH:
  ubytování ve snížené sazbě **12 %**, daň se počítá **shora** z ceny (cena zůstává
  koncová včetně DPH), přibývá sloupec sazby, **rekapitulace DPH** (základ / daň /
  s daní) a poznámka, že faktura je daňový doklad. QR Platba i částka k úhradě zůstávají
  na koncové ceně. Identifikovaná osoba a neplátce fakturují beze změny, s příslušnou
  poznámkou o režimu. Každá faktura si drží svůj profil a sazbu i po pozdější změně
  nastavení. U **plátce** navíc **ekonomika** nepočítá reverse charge z provize OTA
  jako náklad — má nárok na odpočet, takže se v zisku neprojeví (v přehledu i na kartě
  rezervace je označen jako „odpočet"). Přehled **DPH** (`/dph`) rozlišuje profil: u plátce vedle reverse charge z provize (21 %) ukazuje výstupní DPH z faktur a výslednou **daňovou povinnost** (výstup + RC − odpočet) a nabízí **CSV podklad** dokladů pro přiznání; identifikovaná osoba vidí jen reverse charge. U **neplátce** se celý DPH modul skryje (menu, dashboard i přehled) a připomínka DPH se neposílá.
- **Uživatelské role.** Uživatelé se spravují v **Uživatelé** (jen pro admina).
  Každý má jednu **roli**: **Admin** (vše včetně nastavení a správy uživatelů),
  **Správce** (provoz i finance — rezervace, faktury, účty, elektřina),
  **Uklízečka** (jen úklid, bez údajů hostů). Admin zakládá účty, mění role,
  resetuje heslo a účet deaktivuje; menu i stránky se řídí rolí a uklízečka po
  přihlášení přistane rovnou na úklidu. Aspoň jeden aktivní admin musí vždy zůstat.
- **Přihlášení může zůstat platné 30 dní.** Na přihlašovací obrazovce je volba
  **Zůstat přihlášen 30 dní** (ve výchozím stavu zapnutá) — po jejím potvrzení tě
  aplikace nevyhodí po zavření prohlížeče, ale drží přihlášení po dobu 30 dní.
- **Profil s vlastním nastavením účtu.** Kliknutím na e-mail v horní liště se
  otevře stránka **Můj profil** — přehled účtu (e-mail, role, datum založení) a
  formulář pro **změnu hesla** (ověření současného hesla, kontrola délky a shody
  potvrzení).
- **Žádost o zálohu a potvrzení rezervace posílá aplikace sama.** Po objednávce
  webové/přímé rezervace se zálohou dostane host e-mail s pokyny k platbě zálohy —
  částka, číslo účtu, variabilní symbol, splatnost a **QR kód pro rychlou platbu**.
  Jakmile záloha dorazí (nebo ručně tlačítkem **Potvrdit a poslat hostovi** na detailu
  rezervace), host dostane potvrzení, že rezervace platí, a stav se přepne na
  „Potvrzeno". Obě zprávy mají editovatelnou šablonu v **Nastavení → Zprávy hostům**,
  ve výchozím stavu vypnutou — zapneš je, až je budeš chtít odesílat. Podrobný příjezd
  a předání klíčů nese dál zpráva před příjezdem.
- **Rezervace z vlastního webu naskočí okamžitě.** WordPress po vytvoření i potvrzení
  rezervace ťukne na aplikaci a ta si ji hned dotáhne — bez čekání na pravidelnou kontrolu.
  Adresu pro toto propojení najdeš v **Nastavení → Připojení → Okamžitý import z webu**
  (kopírování i vygenerování nové), do WordPressu se vloží přiložené rozšíření
  `integrations/wordpress/ubytovadlo-motopress-webhook.php`. Pravidelná kontrola
  běží dál jako záloha, kdyby ťuknutí nedorazilo.

### Opraveno

- **Odchozí HTTP funguje i na hostingu bez `curl_multi_exec`.** Volání ven
  (import z MotoPressu přes webhook, kurzy ČNB, ARES, iCal feedy) používají
  stream-based HTTP klient, takže projdou i tam, kde je ve webovém PHP zakázaná
  funkce `curl_multi_exec`.

## [0.7.0] — 2026-07-05

### Přidáno

- **Logo instance se nahrává v Obecném nastavení.** Nahraješ PNG nebo JPG (max 2 MB),
  ukáže se náhled a jde odebrat. Logo se objeví v hlavičce e-mailů hostům a na
  fakturách; když žádné není, hlavička je bez něj.

### Opraveno

- **Konzolové příkazy fungují i proti ještě nezmigrované databázi.** Instalace a
  cache warmup (`assets:install`, `cache:clear`) se nezastaví na chybějící tabulce
  settingů — URL kontext pro generování odkazů v CLI prostě zůstane na výchozí
  adrese, dokud tabulka nevznikne.

### Změněno

- **Horní menu je responzivní a jen pro přihlášené.** Na úzkém displeji se položky
  sbalí pod tlačítko (hamburger) místo aby přetekly a část zmizela; nepřihlášenému
  se navigace nezobrazuje.

- **Nastavení má levý sidebar.** Záložky nastavení jsou v bočním panelu vlevo
  seřazené podle důležitosti (Obecné, Fakturace, Připojení, E-maily, Šablony zpráv,
  Notifikace, Úklid, Ubyport); obsah zůstává v čitelně široké koloně, stránky
  s náhledem e-mailu ji roztahují na dva panely. Kliknutí na **Nastavení** otevře
  první záložku a záložka s identifikátory pro cizineckou policii se jmenuje **Ubyport**.

- **Veškerá provozní konfigurace je v databázi, ne v env.** Dodavatel na faktuře,
  číselná řada, záloha, název a adresa instance i přístupy k automatizační schránce
  (IMAP), MotoPressu a SMTP se nastavují výhradně v aplikaci a ukládají do DB.
  V env zůstávají jen `APP_ENV`, `APP_SECRET`, `APP_CREDENTIALS_KEY` (klíč k
  šifrování údajů v DB) a `DATABASE_URL`. Nenastavená položka je prázdná, dokud ji
  nevyplníš v UI (dashboard checklist ukáže, co chybí). *Přesunuto z env do UI.*

- **MotoPress je volitelný konektor, autoritou je Ubytovadlo.** Import rezervací
  z webu jde na Připojení vypnout (přepínačem konektoru **Web (MotoPress)**);
  bez vyplněných přístupů se sync tiše přeskočí. Novou rezervaci import naplní
  celou, ale u **existující** rezervace už jen doplní prázdná pole, srovná termín
  a promítne zrušení — ručně upravené jméno, cenu ani stav nepřepíše.

### Přidáno

- **Obsazenost z iCal feedů OTA nezávisle na MotoPressu.** Ke každému kanálu
  **Booking.com**, **Airbnb**, **eChalupy** a **CS chalupy** lze na stránce
  **Připojení** vložit adresu iCal feedu obsazenosti. Úloha `app:ical:sync`
  (z cronu) z něj zakládá a udržuje rezervace jako blokátory termínu — bere jen
  příjezd, odjezd a identitu bloku, jméno ani cenu ne. Blok, který z feedu zmizí
  a jehož pobyt ještě neskončil, se automaticky **stornuje**. Když už termín
  eviduje jiný zdroj, feed se s ním sloučí a nevznikne duplikát. Kanály
  **eChalupy** a **CS chalupy** přibyly i do přehledů a ekonomiky.

- **Nastavitelná záloha.** Na stránce **Fakturace** se určuje, kolik a kdy platí
  host předem u web klasiky a ručních rezervací: výše jako **fixní částka**,
  **procento z ceny**, nebo **bez zálohy** (pak jde rezervace rovnou na jednu
  fakturu na celou částku), plus splatnost ve dnech. Tlačítko na vystavení zálohy
  i připomínka doplatku se řídí tímto nastavením; Booking a Airbnb si platby řeší
  sami, u nich se záloha neúčtuje. Vše v korunách.

- **Konektory se zapínáním a přehledem zdraví.** Na stránce **Připojení** je nahoře
  přehled zdrojů rezervací a plateb — **Web (MotoPress)**, **Booking.com**, **Airbnb**
  a **Banka (Česká spořitelna)**. Každý jde přepínačem **zapnout/vypnout**; vypnutý
  konektor pollery přeskočí. U každého je stav (v pořádku / chyba / dlouho bez dat /
  nenastaveno), čas poslední přijaté zprávy a tlačítko **Otestovat**, které ověří
  přístup (MotoPress přes REST, ostatní přihlášením do automatizační schránky), aniž
  by měnilo data. Poll schránky i sync webu se u nevyplněných přístupů tiše přeskočí
  a jejich výsledek se propíše do stavu konektoru.

- **Stav platby a ruční platby.** Rezervace (web i přímá) má na detailu i v seznamu
  štítek **Nezaplaceno / Částečně / Zaplaceno** (spočítaný z ceny, zaplacených
  faktur a ručních plateb). Na detailu v kartě Finance jde **zaznamenat platbu
  hosta** (částka + datum) — hotovost, převod nebo záloha bez faktury, nezávisle
  na MotoPressu. Platba se započítá do „Zaplaceno / Zbývá doplatit" i do přehledu
  příjmů a jde smazat. U OTA rezervací zůstává „Reálná výplata".

- **Export obsazenosti (iCal).** Na stránce **Připojení** je adresa kalendáře se
  všemi rezervacemi jako obsazenými termíny — vloží se do Booking i Airbnb
  extranetu jako import kalendáře, takže OTA neprodá termín obsazený jiným
  kanálem, webem ani přímou rezervací. Feed je veřejný přes neuhodnutelný token
  v adrese a nese jen „obsazeno" (žádné údaje hosta). Zrušené rezervace vynechává.

- **Ruční přidání rezervace.** Na seznamu rezervací tlačítko **Nová rezervace**
  otevře formulář pro přímého hosta (telefon, e-mail, osobně) — termín, cena,
  fakturační režim a údaje hosta na jednom místě, bez potřeby webu či OTA. Uloží
  se jako potvrzená rezervace kanálu **Přímá** a rovnou naplánuje akce na časové
  ose. Vše ostatní (faktura, zprávy) se dořeší z detailu jako u ostatních rezervací.

- **Checklist „co ještě nastavit" na dashboardu.** Onboarding karta na přehledu
  ukazuje, co je vhodné doplnit (název a adresa aplikace, dodavatel na faktuře,
  odchozí e-maily, SMTP, automatizační schránka, napojení na web, ubytovací
  zařízení). Každá položka má tlačítko **Nastavit** (vede rovnou na příslušnou
  záložku) nebo **Nepoužívám** (skryje ji). Nastavené i skryté položky zmizí;
  skryté jde vrátit odkazem **Zobrazit skryté**. Přístupy (SMTP, IMAP, MotoPress)
  se berou za nastavené, jen když je provozovatel uložil v UI. Karta se ukáže,
  jen když je co řešit.

- **Nastavení chování MotoPressu v UI.** Na stránce **Připojení** sekce „MotoPress —
  chování": ID služeb pro „host se psem" a „dětskou postýlku" (čárkami oddělený
  seznam) a přepínač, zda posílat potvrzené platby zpět do MotoPressu. Ukládá se do
  DB (`Setting`), env `MOTOPRESS_PET_SERVICE_IDS` / `MOTOPRESS_BABY_COT_SERVICE_IDS`
  / `MOTOPRESS_PUSH_PAYMENTS` slouží jako fallback. Zdroj nastavení je `MotoPressSettings`
  (mapper i push listener ho čtou přes něj).

- **Číselná řada faktur v UI.** V záložce nastavení **Fakturace** (dodavatel +
  bankovní spojení + číselná řada) lze nastavit **formát čísla faktury** a **příští
  pořadové číslo**. Formát používá proměnné `{RRRR}`/`{RR}` (rok) a `{NNN}` (pořadí,
  počet písmen = počet cifer) plus libovolný pevný text a oddělovače — např.
  `FA-{RRRR}-{NNN}` → `FA-2026-012`. Pořadové číslo se drží ve vlastním sloupci
  (`invoice.series_sequence`), takže na formátu nezáleží při alokaci ani při čtení
  nejvyššího čísla; variabilní symbol zůstává číselný nezávisle na formátu. Formát,
  navázání řady i příští číslo se ukládají do DB (`invoice.number_format`,
  `invoice.series_starts`), env `INVOICE_SERIES_STARTS` je fallback.

- **Obecné nastavení instance v UI.** Záložka `/nastaveni/obecne`: **název instance**
  (brand — hlavička, titulky, faktury) a **veřejná adresa aplikace** (pro odkazy
  v e-mailech odeslaných z cronu). Ukládá se do DB (`Setting`), env `APP_BRAND_NAME`
  a `DEFAULT_URI` slouží jako fallback. Twig global `brand_name` čte hodnotu při
  renderu (`App\Config\BrandName`); odkazy v CLI/cron mailech berou adresu ze
  nastavení přes `RouterContextConsoleSubscriber`.

- **SMTP nastavení v UI místo `.env`.** Přihlašovací údaje odchozí pošty (host, port,
  šifrování, uživatel, heslo) se zadávají v `/nastaveni/pripojeni` a ukládají
  **šifrovaně** v credential store (stejně jako IMAP/MotoPress). `App\Mail\DbMailer`
  dekoruje výchozí mailer a staví transport z DB (`CredentialProvider::smtpDsn()`),
  s fallbackem na `MAILER_DSN` z prostředí, když v DB SMTP není. Krok k odstranění
  `.env` konfigurace kvůli budoucímu SaaS — tajemství i konfigurace patří do nastavení.

- **E-mailové notifikace ubytovateli.** Nová záložka nastavení `/nastaveni/notifikace`:
  jedna adresa příjemce a per-typ režim doručení — **okamžitě** (samostatný e-mail
  při nejbližším běhu), **denní souhrn** (jednou denně vše dohromady) nebo **vypnuto**.
  Typy: nová rezervace (web i OTA), přišla/spárovaná platba, host dokončil check-in,
  selhání odeslání zprávy hostovi, připomínka DPH přiznání (~20. dne za měsíc s přijatou
  provizí) a cizinec k nahlášení na Ubyport. Triggery jen zakládají záznam do fronty
  (`PendingOwnerNotification`), doručení řeší cron: `app:notifications:dispatch`
  (á 15 min, okamžité jednotlivě), `app:notifications:digest` (1× denně souhrn) a
  `app:vat:remind` (denně, připomínku DPH zařadí do fronty). Odesílání sdílí layout
  a barevné téma e-mailů hostům (nový `App\Mail\EmailLayoutRenderer`). Ověření
  doručování: `app:notifications:test` pošle testovací notifikaci na příjemce.

- **Skloňování jmen v oslovení e-mailů (5. pád / vokativ).** Nové proměnné šablon
  `{{ guest_first_name_vocative }}` (křestní jméno) a `{{ guest_last_name_vocative }}`
  (příjmení) vracejí jméno hosta v 5. pádu, takže oslovení zní „Dobrý den, **Petře**,"
  místo „Dobrý den Petr,", případně formálně „Vážený pane **Nováku**". Přibyla i
  proměnná `{{ guest_last_name }}` (příjmení v 1. pádu). Výchozí šablony zpráv hostům
  nově používají vokativ křestního jména. Postaveno na knihovně `granam/czech-vocative`;
  prázdný vstup, jednoslovná i cizí jména jsou ošetřené (`App\Mail\GuestVocative`).

### Změněno

- **Sjednocený vzhled stránek nastavení.** Všechny záložky `/nastaveni/*` mají stejnou
  šířku obsahu, tlačítko **Uložit** vpravo pod kartou a nadpisy sladěné s názvy záložek
  („Úklid", „Notifikace ubytovateli"). Editace šablony zprávy běží v kartě jako ostatní
  stránky. Popis záložky **E-maily** odkazuje na SMTP přístup v záložce **Připojení**.

- **MotoPress na záložce Připojení pohromadě.** REST API přístup i chování (mapování
  služeb pes/postýlka, push plateb) jsou v jedné kartě „MotoPress — REST API a chování"
  a ukládají se jedním tlačítkem. Stránka Připojení je členěná po systémech: IMAP
  schránka, MotoPress, SMTP.

- **Dodavatel faktury bez předvyplněné falešné identity.** Výchozí hodnoty dodavatele
  a bankovního spojení (`.env`) jsou prázdné — profil se vyplňuje v UI
  **Fakturace**. Dokud není vyplněný, faktury ani odchozí e-maily nepoužijí žádnou
  náhradní identitu (odesílatel zpráv hostům čerpá jméno/e-mail právě odtud).

## [0.6.0] — 2026-07-02

### Přidáno

- **Zvýraznění dneška v seznamu rezervací.** V `/rezervace` (řazeno podle příjezdu
  sestupně) přibyl dělič **„Dnes"** mezi budoucí a probíhající/minulé rezervace,
  zvýraznění řádku právě probíhajícího pobytu (dnešek mezi příjezdem a odjezdem)
  a odznak „dnes" u příjezdu/odjezdu, který připadá na dnešní datum.

- **Evidence účtů, výdajů a uzávěrek (cashflow modul).** Nová sekce `/ucty`:
  univerzální **účty** (banka / hotovost, uživatelsky definované; entita `Account`),
  **výdaje a převody** mezi vlastními účty v jednotném ledgeru (`LedgerEntry`:
  výdaj / převod / korekce), a **uzávěrky** (`BalanceStatement`) — ruční snapshot
  reálného stavu účtu, ke kterému systém dopočítá **očekávaný stav** a ukáže rozdíl;
  rozdíl lze jedním klikem srovnat korekcí. Výdaje mají kategorie (`ExpenseCategory`)
  s rozlišením provozních (jdou do Ekonomiky) a nevýdělkových (splátka úvěru, osobní
  výběr — jen snižují stav účtu). Ekonomika (`/ekonomika`) nově ukazuje samostatný
  blok **„Obecné výdaje"** (provozní kategorie), mimo per-rezervační zisk.
- **Reálně přijaté platby per rezervace (`ReservationReceipt`).** **Dílčí platby** —
  víc řádků na rezervaci, každý s **vlastním datem přijetí**, upsertované podle
  původu (`originType` + `originId`) s rozlišením kanálu:
  - **Přímá objednávka (web):** reálný příjem = **každá zaplacená faktura** — u web
    klasiky **záloha** (přichází dřív, typicky jiný měsíc) a **doplatek** (při
    příjezdu) jako dva samostatné řádky se svými daty, takže měsíční cashflow sedí;
    jinak spárované bankovní platby. Dokud host nezaplatí, na účtu nic není.
    Napojeno na `InvoiceService::markPaid` a spárování platby (`PaymentSettledEvent`).
  - **Airbnb / Booking:** faktura vystavená v průběhu pobytu je jen doklad; příjem
    se vede jako **odhad net (hrubá − provize)** a **nahradí se reálnou výplatou** —
    Airbnb automaticky z výplatního mailu, u Bookingu (a obecně) **ručně** přes
    formulář „Reálná výplata" na detailu rezervace. Ruční výplata odhad „zamkne".
  Idempotentní přepočet (`app:cashflow:recompute-incomes`) auto-platby synchronizuje,
  ruční nechává být → stejná platba se nikdy nezapočte dvakrát. Do **stavu účtu**
  vstupují jen **skutečně přijaté** platby (odhad = výhled, mimo zůstatek);
  **zrušené a nedotažené** rezervace se nepočítají. `/ucty` zobrazuje přijaté platby
  i očekávané výplaty (výhled); detail rezervace ukazuje rozpis dílčích plateb.
  Demo seed zakládá účty, výdaje, převod a uzávěrku na neutrálních datech.
- **Cashflow UI — editace, filtr, měsíční souhrn, CSV export.** Pohyby (`LedgerEntry`)
  i účty (`Account`) lze **upravit**, ne jen přidat/smazat. Přehled pohybů i tabulka
  **přijatých příjmů** mají **stránkování** (nezávislé); pohyby navíc **filtr**
  (účet / typ / období). Nová stránka **měsíční souhrn** (`/ucty/souhrn/{rok}`):
  přijaté platby proti výdajům (provozní vs. osobní odliv) po měsících;
  **CSV export** filtrovaných pohybů.
- **Nerezervační příjmy** (`LedgerEntryType::INCOME` — „ostatní příjem"): úroky
  z účtu, storno-poplatky, náhrady. Formulář na `/ucty`, vstupují do stavu účtu
  i do měsíčního souhrnu (ne do per-rezervačního zisku v Ekonomice).
- **Kategorie výdajů — skupiny a lepší členění.** Výdaje rozdělené do skupin
  **„Provoz ubytování"** vs **„Osobní a finanční"** (`ExpenseGroup`): dropdown je
  seskupený (optgroup) a osobní odliv (splátka úvěru, výběr majitele) je v přehledu
  vizuálně odlišený. Přibyly kategorie **úklid, spotřební materiál a drogerie,
  pojištění** a osobní **„ostatní"** (osobní nákupy mimo výběr/splátku); popisky zpřesněny.
- **Zrušená rezervace se zaplacenou fakturou vede příjem** — nevrácená záloha /
  storno-poplatek je reálný příjem (peníze přišly a nevrátily se); u zrušeného
  pobytu se nevede jen odhad budoucí OTA výplaty.

### Opraveno

- **Airbnb `price_total` = hrubá tržba hostitele, ne guest total.** Parser bral cenu
  z „Celkem (CZK)" — jenže Airbnb tak označuje **částku zaplacenou hostem** (včetně
  servisního poplatku hosta a daní, které jdou Airbnb a hostiteli nikdy nedojdou).
  Nově bere **výdělkovou stranu** (čistý výdělek „Vyděláš si" + servisní poplatek
  hostitele 3 %). Opravuje nadhodnocený Airbnb příjem v Cashflow i Ekonomice
  (např. odhad 3 967 → reálných 3 298 Kč).

- **Párování příchozích plateb (notifikace ČS) → automatické vystavení faktury.**
  IMAP poller nově zpracovává e-mail České spořitelny „Přišla platba"
  (`App\Email\CsPaymentParser`): vytáhne částku, variabilní symbol, účet protistrany
  a směr platby. `App\Payment\PaymentProcessor` platbu zaeviduje (nová entita
  `Payment` jako source of truth „peníze dorazily", i pro nespárované platby) a
  napáruje podle VS — buď na fakturu (VS = číslo faktury, host platil z QR), nebo
  na rezervaci (VS = MotoPress booking ID). U webové klasiky (záloha + doplatek)
  ve výši zálohy vystaví (chybí-li) a označí **zálohovou fakturu uhrazenou**,
  doplní časovou osu. Vystavení přeskočí, pokud rezervaci chybí údaje hosta
  (platba se přesto zaeviduje). Odpadá ruční potvrzování platby. Demo seed
  zakládá ukázkové platby na neutrálních datech.
- **Volitelný push stavu platby do MotoPressu.** Po spárování platby vyšle jádro
  doménovou událost `PaymentSettledEvent`; konektor `MotoPressPaymentSyncListener`
  na ni reaguje a přes REST API označí odpovídající platbu v MotoPressu jako
  `completed` (MotoPress si rezervaci sám potvrdí). Zapíná se přepínačem
  `MOTOPRESS_PUSH_PAYMENTS` (default vyp.; vyžaduje API klíč s právem Write).
  Jádro o MotoPressu neví — instance bez něj listener nemá a událost zůstane bez
  efektu. Selhání MotoPressu jen zaloguje, zpracování platby neshodí.

- **Přístupové údaje v UI místo .env (šifrovaně v DB).** Nová stránka
  `/nastaveni/pripojeni` — IMAP schránka a MotoPress REST API se zadají v UI a uloží
  **šifrovaně** v tabulce `credential` (libsodium secretbox; master klíč
  `APP_CREDENTIALS_KEY` zůstává v env). `CredentialProvider` čte přednostně z DB,
  fallback je `.env` (self-host/vývoj funguje beze změny). Tajemství se v UI nikdy
  nezobrazují zpět (write-only, prázdné = beze změny). Připraveno na hostovaný/
  multi-tenant provoz, kde si creds vyplní uživatel a nesmí do env.

### Změněno

- **Časová osa rezervace u faktura událostí skrývá čas.** Faktura eviduje jen
  datum (pole `issuedAt`/`paidAt` jsou date-only), takže časová osa u „Vystavena
  faktura" a „Zaplacena faktura" ukazovala zavádějící `00:00`. Nově se u těchto
  událostí zobrazuje jen datum (`TimelineItem::event(dateOnly: true)`); ostatní
  události s reálným časem beze změny.
- **Region hosta z Airbnb e-mailu se extrahuje strukturálně** (region = „Město/kraj,
  jednoslovná země" za značkou „Totožnost ověřena") — odpadá konfigurace
  `AIRBNB_LISTING_NAME`, která je tím zrušena.
- **`cache:clear` odebrán z composer `auto-scripts`** (post-install). Na PHP 8.5
  hostu spouštěl composer warmup v subprocessu s nezapisovatelným temp direm →
  `tempnam()` `E_NOTICE`, kterou symfony/translation 7.4.x nepotlačuje (fix až
  Symfony 8), a warmup padal. Ruční `bin/console cache:clear` (čisté CLI) funguje;
  je proto povinným krokem deploye (viz `docs/deploy.md`).

## [0.5.0] — 2026-06-15

### Přidáno

- **Markdown toolbar editoru** — nad tělem zprávy (`/nastaveni/zpravy/{kind}`)
  i patičkou e-mailů (`/nastaveni/mail`) lišta s ikonami: zpět/vpřed (undo/redo),
  tučně, kurzíva, nadpis, odkaz, citace, kód, odrážky, číslovaný seznam, oddělovač.
  Formátování řeší web component `@github/markdown-toolbar-element` (27 kB, bez
  závislostí, vendorováno, ESM modul), undo/redo a oddělovač `markdown-editor.js`.
  Sdílený Twig partial `_partials/markdown_editor.html.twig`. Pracuje nad nativní
  `<textarea>`, takže paleta proměnných i živý náhled fungují beze změny (úprava
  dispatchne `input` event).
- **CTA tlačítko do e-mailu** — v těle zprávy lze přes syntaxi `[[button:Text|odkaz]]`
  (typicky `[[button:Dokončit check-in|{{ checkin_url }}]]`) vložit výrazné tlačítko;
  vyrenderuje se jako **vycentrované** table-based tlačítko v barvě akcentu tématu
  (kompatibilní s poštovními klienty). Vkládá ho i tlačítko v toolbaru editoru. Popisek i odkaz se HTML-escapují.
  Ukázková rezervace pro náhled dostala check-in token, takže `{{ checkin_url }}` v náhledu
  i testovacím odeslání vede na reálnou adresu. Výchozí šablona „Před příjezdem" používá
  pro odkaz na online check-in právě toto tlačítko.
- **Server-side náhled patičky** (`/nastaveni/mail`) — patička se v náhledu
  vzhledu renderuje z Markdownu na serveru (`GuestMessageRenderer::renderFooterPreview`,
  endpoint `mail_settings_footer_preview`), shodně se skutečným e-mailem a s dosazením
  proměnných z ukázkové rezervace. Dřív se ukazovala jen jako prostý text.

### Změněno

- **Drobné úpravy popisů v nastavení** — odebrán nadbytečný popis u „Jméno odesílatele"
  (opakoval label), z intro „Dodavatel na faktuře" vypuštěn vývojářský žargon o `.env`.
  CTA tlačítko v náhledu nastavení e-mailů vycentrované.
- **Šablony zpráv jsou nově defaultně s vypnutým odesíláním** (`enabled = false`).
  Žádná zpráva hostům se neodešle, dokud konkrétní šablonu ručně nezapneš —
  bezpečnější výchozí stav (nehrozí nechtěné automatické odeslání). Týká se nových
  i dosud nenakonfigurovaných druhů zpráv; uložené šablony si svůj stav drží.
- **Zprávy hostům e-mailem** (roadmap bod 4) — odesílání naplánovaných zpráv přes
  Symfony Mailer (SMTP hostingu, `MAILER_DSN` v `.env.local`). Tři vrstvy:
  - **Nastavení e-mailů** (`/nastaveni/mail`) — odesílatel, Reply-To, patička,
    zobrazení loga a barevné téma (5 presetů + vlastní hexy). Klíče `mail.*`
    v tabulce `setting`, `MailSettingsProvider` s fallbackem na dodavatele faktury.
  - **Šablony zpráv** (`/nastaveni/zpravy`) — editovatelný předmět + tělo v Markdownu
    s proměnnými (`{{ guest_first_name }}`, `{{ check_in }}`, `{{ balance_due }}`, …)
    pro 5 druhů (před příjezdem, po pobytu, připomínka doplatku, faktura, vlastní).
    Výchozí texty v kódu (`MessageTemplateDefaults`), DB drží jen override
    (`message_template`). Paleta proměnných, **živý náhled** a **testovací odeslání**
    na vlastní e-mail. Proměnné se dosazují bezpečně (whitelist, ne Twig nad textem).
  - **Master layout** (`templates/email/_layout.html.twig`) — table-based HTML
    s tématem; tělo (Markdown→HTML přes `league/commonmark`, `html_input: escape`)
    a patička se vloží dovnitř, logo přes `cid`.
  - Odeslání zapojeno do `app:actions:run`: pre-arrival / post-stay / vlastní zpráva
    se v okně platnosti odešlou (jinak SKIPPED), připomínka doplatku pošle hostovi
    jednu výzvu, dokud není uhrazeno. Audit + pojistka proti duplicitě v `guest_message`.
  - **Faktura e-mailem** (roadmap bod 2, poslední kus) — tlačítko na detailu
    rezervace odešle hostovi vystavenou fakturu v PDF příloze (druh zprávy `invoice`).

## [0.4.0] — 2026-06-14

### Přidáno

- **Ochrana importovaných faktur před regenerací** — `Invoice.pdfSource`
  (`generated` / `external`). `app:invoice:regenerate-pdf` přeskakuje externí
  (importované / ručně nahrané) PDF, která aplikace neumí reprodukovat; `--force`
  vynutí přepis. Které faktury jsou externí = data instance (backfill), ne kód.

## [0.3.0] — 2026-06-14

### Přidáno

- **Dodavatel na faktuře v nastavení aplikace** — fakturační identita (jméno,
  adresa, IČO/DIČ, kontakt, číslo účtu, IBAN) se nastavuje v UI
  `/nastaveni/dodavatel` a ukládá do DB (tabulka `setting`) místo editace `.env`.
  Každá instance si dodavatele nastaví sama. `IssuerProfileProvider` s fallbackem
  na `.env` (`INVOICE_ISSUER_*`) pro nenakonfigurované instance / vývoj.

## [0.2.1] — 2026-06-14

### Změněno

- **Okno platnosti zpráv hostům** — naplánovaná zpráva (pre-arrival/post-stay/
  custom) se po vypršení okna označí `SKIPPED` místo věčného visení jako „po
  termínu". Pre-arrival platí do příjezdu, post-stay do 3 dnů po odjezdu. Pojistka,
  aby se po nasazení odesílání nikdy neodeslaly prošlé zprávy zpětně.

### Opraveno

- **Deploy na sdílený hosting s nezapisovatelným `/tmp`** — `config/tmpdir.php`
  přesměruje `TMPDIR` na projektový `var/tmp`, když je systémový temp mimo
  open_basedir. Bez toho padal `cache:clear` (Symfony XliffUtils → `tempnam()`)
  na PHP 8.5. Zapojeno do všech vstupních bodů (CLI, web, shim, cron bootstrap).
  Aktivuje se jen na postižených hostech, jinde no-op.

## [0.2.0] — 2026-06-14

### Přidáno

- **Časová osa rezervace + CRM poznámky** — na detailu rezervace jeden chronologický
  feed: systémové události (založení, faktura, check-in, výplata) + ruční typované
  poznámky (poznámka/hovor/e-mail/zpráva/osobně) + naplánované akce (zpráva před
  příjezdem/po pobytu, doplatková faktura, připomínka doplatku, Ubyport u cizinců)
  s možností odložit/upravit/zrušit/spustit. Automatické akce zakládá idempotentní
  plánovač (cron `app:actions:plan`), `app:actions:run` vyhodnocuje akce po termínu.
- **Doplatek u ceny** — kolik hostovi zbývá doplatit (cena − zaplacené faktury).

### Známá omezení

- Odesílání zpráv hostům e-mailem zatím není v provozu — akce typu zpráva se jen
  plánují a zobrazují (čeká na samostatnou funkci „Zprávy hostům").

## [0.1.1] — 2026-06-12

### Zabezpečení

- Aktualizace závislostí — oprava 33 security advisories (`composer update`),
  sync `composer.lock` content-hashe s `composer.json`.

## [0.1.0] — 2026-06-12

První veřejné vydání. Ubytovadlo běží v reálném provozu; tohle je čistý
iniciální snapshot vyčleněný z interního vývoje (bez přenosu git historie).

### Přidáno

- **Sběr rezervací ze 3 kanálů** — vlastní web (MotoPress REST sync), Booking.com
  (IMAP trigger z notifikačního e-mailu) a Airbnb (parser potvrzovacího e-mailu).
  Všechny rezervace v jedné databázi bez ohledu na zdroj.
- **Fakturace hostům** — generování PDF (mPDF), QR Platba (SPAYD), vlastní číselná
  řada, přepočet EUR→CZK kurzem ČNB, pět fakturačních toků (web klasika se zálohou,
  FKSP, admin/známí, Airbnb, Booking) mapovaných z platební brány.
- **DPH modul pro identifikovanou osobu** (§6h ZDPH) — reverse charge z provizí
  Booking/Airbnb, import měsíční Booking faktury z PDF, ruční upload Airbnb receiptu,
  agregace `VatPeriod`, reconciliation a přehled `/dph`.
- **Ekonomika** — zisk per rezervace (po odečtu elektřiny, úklidu, rekreačního
  poplatku, provizí a DPH), přehled `/ekonomika` dělený na uskutečněno vs. očekáváno.
- **Online check-in** — sběr dokladů jen u cizinců (Ubyport), MRZ sken pasu
  (upload i live kamera), doplnění fakturační adresy objednatele s ARES autofillem z IČO.
- **Úklid** — evidence úklidů s konfigurovatelným ceníkem podle typu.
- **Login + security**, **dashboard** s přehledem nadcházejících pobytů, úklidů,
  faktur k vystavení a daňových lhůt.
- **Deploy na sdílený hosting** — bez daemon procesů, úlohy přes cron
  (IMAP poller, MotoPress sync). Runbook v [`docs/deploy.md`](docs/deploy.md).
- **CI** — php-cs-fixer, PHPStan (level 6) a PHPUnit (186 testů) přes GitHub Actions.

### Známá omezení

- Odesílání faktur a zpráv hostům e-mailem zatím není automatizované.
- iCal sanity sync (kontrola obsazenosti, detekce Airbnb storen) chybí.
- Cílí zatím na jednu ubytovací jednotku (multi-unit je v plánu).

[0.11.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.11.0
[0.10.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.10.0
[0.9.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.9.0
[0.8.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.8.0
[0.7.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.7.0
[0.6.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.6.0
[0.5.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.5.0
[0.4.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.4.0
[0.3.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.3.0
[0.2.1]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.2.1
[0.2.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.2.0
[0.1.1]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.1.1
[0.1.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.1.0
