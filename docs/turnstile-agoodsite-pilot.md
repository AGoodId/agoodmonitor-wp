# Första installation: agoodsite.se

Användaren har 2026-10-17 uttryckligen beställt installation på `agoodsite.se`.
Det ersätter det tidigare första testmålet `agoodid2026.agoodsite.se`.
Endast denna installation omfattas; ingen release till kundsajter eller
installation på Lorensbergs ingår.

## Kontrollerat

- `https://agoodsite.se/wp-admin/plugins.php` leder till sajtens egen
  WordPress-inloggning med titeln **AGoodSite**.
- Användaren återställde lösenordet och loggade in som administratör i
  in-app-webbläsaren den 17 oktober 2026.
- Turnstile-implementationen finns i PR #4. Senast verifierat lokalt med 65
  integrationsassertioner och 15 riktiga HTTP-assertioner. Cloudflare simulerades;
  inga mejl skickades ut från testmiljön.
- Pilotpaketet från commit `3022309` installerades och AGoodMonitor aktiverades
  den 17 oktober 2026. Plugin-headern visar version **1.3.2**. Paketets SHA-256:
  `c97a793e925b787d32e6f04fee5fd3f118ee97ee5ae8534d6fb78b5e5bdd94cf`.
- Ingen AGoodMonitor-version var tidigare installerad. WordPress installerade
  paketet som ett nytt tillägg; ingen befintlig plugin ersattes och ingen tidigare
  AGoodMonitor-katalog behövde säkerhetskopieras. Full sajtbackup och åtkomst till
  wp-config.php är ännu inte verifierade inför senare Turnstile-aktivering.
- Inventerat: WordPress **7.1.3**, PHP **8.3.35**, LiteSpeed. Ingen WooCommerce,
  2FA eller annat CAPTCHA-tillägg i den installerade tilläggslistan. Två drop-ins:
  LiteSpeed object-cache.php och maintenance.php. Password Protected är aktivt.
- Admin visar **Status: Inte konfigurerat**, tomma nyckelfält och avmarkerad
  aktivering/WooCommerce. Inga nycklar sparades och Turnstile är avstängt.
- Verifierat efter aktivering: admin och startsidan renderas, native login- och
  lostpassword-formulär visas. Båda formulärens DOM har noll Turnstile-widgetar
  och noll skript från challenges.cloudflare.com. Inga POST-prov eller nya
  återställningsmejl skickades under installationen. Ett nytt riktigt loginprov
  och Cloudflare-verifiering återstår med separat testkonto.
- WordPress uppdaterade även Site Kits svenska översättning automatiskt som
  del av installationen. Ingen annan pluginuppdatering beställdes eller kördes.

## Installera utan att aktivera formulärskydd

1. Logga in som befintlig administratör. Läs installerad version, pluginmapp,
   tilläggslista, MU-plugins, PHP-version och aktuell Turnstile-status. Stoppa om
   det redan finns en aktiv Turnstile-konfiguration; utred den före installation.
2. Ta backup av den befintliga AGoodMonitor-katalogen och dokumentera dess
   version/checksumma och aktiveringsstatus. Använd server-/hostingbackup eller
   SFTP/SSH. Backa inte upp `wp-config.php` eller databasen till en publik URL.
3. Jämför pilotpaketet med den faktiskt installerade versionen. Installera inte
   ett äldre paket över en nyare version eller sajtspecifika ändringar. Om den
   befintliga versionen avviker: applicera enbart modulfilen, dess bootstrap och
   uninstall-städning på serverns nuvarande version med backup och syntaxkontroll.
4. Installera endast på `agoodsite.se`. Skapa ingen GitHub Release eller tagg.
   Verifiera att **Inställningar → AGoodMonitor Turnstile** syns, att status är
   **Inte konfigurerat**/**Avstängt**, och att login/reset-GET inte laddar
   `challenges.cloudflare.com` från modulen. Kontrollera både startsida och admin.
5. Dokumentera installerad commit/checksumma och eventuella skillnader mot den
   tidigare katalogen. Kontrollera auto-update-inställningen före ett längre
   pilotförsök; en kommande vanlig uppdatering kan ersätta pilotfilerna.

## Aktivering är ett separat verifieringssteg

Säkerställ tillgång till `wp-config.php` för dokumenterad återställning. Skapa
eller välj ett separat testkonto med kontrollerad testbrevlåda; gör inget
resetprov mot ett verkligt användarkonto. Därefter behövs en Cloudflare-widget
med tillåtna värdnamnet `agoodsite.se` och dess site key/secret key. Ange nycklarna
direkt i sajtens inställningar, inte i chatt, PR, skärmdumpar eller loggar.

Kör konfigurationsprovet, aktivera och verifiera med testkonto enligt
[installations- och återställningsinstruktionen](turnstile.md). Testa giltig
inloggning, saknad/felaktig token via direkt POST, resetmejl i testbrevlådan och
valt 2FA-tillägg inklusive reservkod. WooCommerce väljs bara om det faktiskt
används och dess flöden kan provas. Behåll adminsessionen tills proven är klara.

## Återställ installationen

Om installationen orsakar PHP-fel: återställ den säkerhetskopierade
plugin-katalogen via serveråtkomst. Vid utelåsning efter Turnstile-aktivering:
lägg `define( 'AGOODMONITOR_TURNSTILE_DISABLE', true );` i `wp-config.php` före
WordPress-laddningen. Återställningskonstanten påverkar inte separat 2FA.

## Kvar före färdig installation

Installation, Turnstile-aktivering, testkontots inloggningsprov och sparande i
1Password är genomförda, se proven nedan. Tvåfaktorstödet är testat lokalt med
Two Factor. Two Factor 0.17.0 är nu installerat på agoodsite.se; registrering och
verifiering på testkontot återstår enligt avsnittet nedan. PR #4 är
fortsatt ett utkast; ingen release eller installation på kundsajter har gjorts.

## Backup och återställningsåtkomst verifierade 2026-10-17

- cPanel-kontot `agoodsit` har Acronis-backuper. Återställningspunkten
  **17 oktober 2026 kl. 02:43** gick att öppna och innehåller filer och databaser,
  inklusive sajtens databas `agoodsit_wp295` (tabellprefix `wpqv_`). Detta är en
  äldre punkt från före pilotinstallationen, inte en nyss tagen backup.
- En färsk kopia av **hela public_html och databasen** skapades separat före
  Turnstile-aktivering i `/home/agoodsit/.agoodmonitor-backups/20261017-pre-turnstile/`.
  `database.sql` exporterades via WP-CLI och `site.tar.gz` skapades via tar.
  Total storlek cirka **984 MB**. Katalogen har behörighet **700**, filerna **600**
  och ligger utanför alla publika dokumentrötter.
- Gzip-integriteten är kontrollerad. Arkivet innehåller `public_html/wp-config.php`
  och installerad AGoodMonitor. SHA-256 för databasen:
  `85a655fd8e2fcbc3482a5d5708ca9cea0d80484d23562312c66ad25bf195e572`.
  SHA-256 för filarkivet:
  `ae398a4a8fe3fec2a3f084bcfbd2a4831bc97b8401730e1908fe1dbab357edb4`.
  Ingen full återläsning av databas/hela sajten har utförts på den levande sajten.
- Både cPanels filhanterare och terminal når `/home/agoodsit/public_html/wp-config.php`.
  Nödstoppet provades med `wp config set AGOODMONITOR_TURNSTILE_DISABLE true --raw`
  (med `--path=/home/agoodsit/public_html`). Admin visade **Avstängt via
  serverkonfiguration**. Konstanten togs sedan bort med `wp config delete` och
  PHP-syntaxkontrollerades. Detta gjordes medan Turnstile redan var avstängt.
- Vid utelåsning kan samma terminalkommando eller filhanteraren användas för
  nödstopp. Vid pluginfel: inaktivera AGoodMonitor via WP-CLI med
  `--skip-plugins --skip-themes` eller byt namn på pluginmappen via filhanteraren.
  Full återläsning ersätter senare data; välj därför relevanta filer eller
  databas i Acronis, eller läs tillbaka den privata kopian under kontrollerat
  underhåll. Gör ingen full återläsning enbart för att ta bort Turnstile-kravet.

## Separat testkonto

`agoodmonitor_turnstile_test` (ID 8) skapades med rollen **Prenumerant** och
kontrollerad testadress `mats+turnstile-test@agoodid.se`. Ingen administratörsroll
eller kontoinbjudan skickades. Ett baseline-prov av native lösenordsåterställning
med avstängt Turnstile gav bekräftelsesidan och ett verifierat mejl i inkorgen
den 17 oktober kl. 21:15. Endast testkontot berördes. Användaren behöver själv
slutföra lösenordsbytet; inga lösenord eller resetlänkar finns i dokumentationen.
Användaren har därefter bekräftat att testkontots lösenordsbyte är klart.

## Aktivering och riktiga Cloudflare-prov 2026-10-17

- Widgeten **AGoodMonitor – agoodsite.se pilot** skapades efter uttryckligt
  godkännande i Cloudflare-kontot **Mats Ingerdal**. Ett hostname: `agoodsite.se`,
  Managed-läge, pre-clearance av. Ingen DNS-ändring gjordes.
- Nyckelparet överfördes direkt till WordPress. Secret finns inte i denna PR
  eller dokumentation. Efter sparande är secret-input tom och admin anger att
  nyckeln finns sparad. Nyckelparet sparades efter användarens upplåsning i
  1Password, privata **Employee**-valvet, som **AGoodMonitor Turnstile – agoodsite.se**.
  Posten är API Credentials: site key i username, secret key i det dolda
  credential-fältet. Sparad titel, valv och maskerat credential verifierades.
- Det riktiga serverprovet med `agm_setup` och `agoodsite.se` lyckades. Därefter
  aktiverades Turnstile; admin visar **Status: Aktiverat**. WooCommerce är av.
- Fyra direkta POST-prov med testkontot: login och lostpassword, vardera med
  saknad respektive ogiltig token. Samtliga svarade **HTTP 403** med modulens
  botskyddsfel. Testlösenordet i nekade loginprov var avsiktligt ogiltigt.
- Native lostpassword med automatiskt godkänd riktig Cloudflare-token gick
  igenom. Bekräftelsesidan visades och ett nytt mejl till testadressen verifierades
  i inkorgen **kl. 21:49**. Ingen annan användares återställning begärdes.
- Användaren bekräftade **inloggad** efter begärt fullständigt inloggningsprov
  i privat fönster med testkontots nya lösenord. Detta är användarbekräftad
  verifiering; agenten läste inte testlösenordet. Administratörssessionen
  behålls i in-app-webbläsaren.
  Utgången/återanvänd token och driftstörningar har verifierats lokalt enligt
  testsuiten, men simuleras inte på den aktiverade sajten. 2FA-proven följs
  separat nedan och är ännu inte verifierade i denna pilot.

## Two Factor-installation 2026-10-17

Efter separat beställning installerades och aktiverades **Two Factor 0.17.0**,
slug `two-factor`, från WordPress.org:s officiella katalog. Turnstile visar
fortsatt **Aktiverat** efter installationen. Andra användares 2FA-konfiguration
ändrades inte och ingen generell rollpolicy infördes.

Testkontots profil (ID 8) är förberedd för autentiseringsapp som primär metod
och återställningskoder som reserv. Användaren behöver själv registrera den nya
autentiseringshemligheten, verifiera en kod, spara reservkoderna säkert och spara
profilen. Ingen QR-hemlighet eller reservkod sparas i dokumentationen.

Efter registrering ska följande live-prov köras med testkontot: normal login med
Turnstile följt av TOTP, avvisad felaktig TOTP, login med en reservkod, samt nekat
återbruk av samma reservkod. Behåll administratörssessionen under proven.
De två 2FA-stegen ska gå via Two Factors egna handlers utan ett extra
Turnstile-krav. Dessa live-prov är **inte utförda ännu**.

Vid problem med testkontots 2FA: använd den inloggade administratören och ändra
endast testkontots metoder i Användare → testkontots profil. Servervägen finns
även via Two Factors `wp two-factor`-kommandon; en full 2FA-reset är en separat,
avsiktlig säkerhetsåtgärd och ska inte göras automatiskt vid fel. Turnstiles
återställningskonstant påverkar inte Two Factor.
