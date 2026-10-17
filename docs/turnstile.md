# Turnstile: installation och drift

Modulen är en gemensam investering i AGoodMonitor, separat från Lorensbergs
fasta införandepris 7 500 kr exkl. moms. Användaren har 2026-10-17 ändrat första
installationsmålet till **`agoodsite.se`** från `agoodid2026.agoodsite.se`.
Se [installationsprotokoll för agoodsite.se](turnstile-agoodsite-pilot.md).
Lorensbergs väntar på Arvids godkännande. Ingen
kundinstallation, kundaktivering eller generell release ingår i denna PR.

## Aktivera på en sajt

1. Kontrollera WP/PHP-version, andra CAPTCHA-/säkerhetstillägg, 2FA, cache/CSP,
   WooCommerce och eventuella egna inloggningsformulär. Säkerställ fungerande
   serveråtkomst för återställning **innan** aktivering. Behåll adminsessionen.
2. Använd testkonto och en kontrollerad testbrevlåda eller mail capture.
   Rikta aldrig resetprov mot kundanvändare. Testa först på pilotsajten.
3. Skapa en Turnstile-widget i Cloudflare för exakt sajtens värdnamn, normalt
   Managed-läge. Cloudflare-proxy/DNS-ändring behövs inte. Använd riktiga nycklar
   för det verkliga värdnamnsprovet. Dummynycklar ger inte ett godkänt
   produktionsprov med denna moduls strikta hostname-kontroll.
4. Öppna **Inställningar → AGoodMonitor Turnstile**. Status är **Inte
   konfigurerat**, **Avstängt** eller **Aktiverat**. Spara site key och secret
   key, och välj eventuellt WooCommerce. Skyddet är fortfarande avstängt.
5. Kör widgetens **Verifiera konfiguration**. Provet går via WordPress HTTP-API
   till Siteverify och kontrollerar nyckelpar, hostname och `agm_setup`.
   Det skickar inga mejl och loggar inte in någon. Ett godkänt kvitto gäller
   i tio minuter, för aktuell administratör och exakt konfiguration/URL:er.
6. Kryssa **Aktivera skydd** och spara utan andra ändringar. Nyckel- eller
   WooCommerce-ändring stänger av skyddet och kräver ett nytt prov. Misslyckad
   aktivering lämnar status avstängt och visar instruktionen att prova igen.
7. Prova login, resetbegäran, direkt POST, 2FA och reservkoder i separat privat
   fönster med testkonto. Testa även WooCommerce om valt. Behåll adminsessionen
   tills både lyckade och nekade försök är kontrollerade.

Inga externa bibliotek eller byggsteg krävs. Nycklarna lagras per blogg i ett
icke-autoloadat option, inte i ett network-option. Databasen innehåller secret
i klartext och måste ha samma åtkomst- och backupskydd som andra WP-hemligheter.
Secret-input är alltid tom i serverns HTML; tomt vid sparande behåller nyckeln.
Kryssrutan för radering tar bort den. Avinstallation raderar konfigurationen.
Konfigurationskvitton innehåller bara en HMAC och löper ut efter tio minuter.

## Fel och återställning

Saknad, felaktig, utgången/återanvänd token, fel hostname/action, felaktiga nycklar,
icke-200-svar, trasig JSON, nätverksfel och timeout ger **403 före den skyddade
åtgärden**. HTTP-anropet har åtta sekunders timeout och följer inga redirects.
Ingen automatisk fail-open eller dold reservväg finns. En besökare får ett
generellt fel och uppmanas ladda om formuläret eller kontakta administratören.
Driftstörning kan därför tillfälligt stoppa nya inloggningar och resetbegäranden.
Redan inloggade sessioner påverkas inte av modulen.

Vid utelåsning: lägg via SSH/SFTP/hostingens filhanterare in följande i
`wp-config.php`, **före** raden som laddar `wp-settings.php`:

```php
define( 'AGOODMONITOR_TURNSTILE_DISABLE', true );
```

Detta kopplar bort modulens skydd och widgets utan att inaktivera AGoodMonitor.
Admin visar en varning. Logga in, stäng av modulen i inställningarna, rätta
orsaken, ta sedan bort konstanten, kör nytt konfigurationsprov och aktivera
kontrollerat. Konstanten kringgår inte 2FA, WAF eller annan leverantörs CAPTCHA.
Detta är ett medvetet administratörsingrepp som tillfälligt tar bort botskyddet;
lämna det inte kvar efter felsökning.

Vanliga fel: fel domän i Cloudflare, omkastade nycklar, blockerad extern HTTPS,
CSP som blockerar `challenges.cloudflare.com`, scriptoptimering som ändrar
Cloudflares API-script, eller cachelagrat formulär. Ladda script direkt från
Cloudflare; undanta auth-sidor från helsidescache och aggressiv JS-optimering.
Cloudflares widget hanterar normal tokenförnyelse; efter en nekad insändning
behövs en omladdning och ny token. Prova med JavaScript avstängt: formuläret
ska förklara att JS krävs och servern ska fortfarande neka saknad token.

## Omfattning och konflikter

- WP: tidiga `login_form_login`, `login_form_lostpassword` och
  `login_form_retrievepassword` blockerar POST innan core autentiserar eller
  skickar resetmejl. Widget visas via `login_form` och `lostpassword_form`.
- WooCommerce: tillval för standardformulär, inklusive login-formulär som
  använder samma WC-handler vid checkout. Vid `wp_loaded:-1000` kontrolleras
  samma POST-markörer som WC använder innan `WC_Form_Handler` behandlar dem
  vid prioritet 20. Det gäller även direkt POST och försök att lägga till
  `wc-ajax`. Nonces från WooCommerce krävs fortfarande av dess egna handlers.
- Ingen global `authenticate`-filter. REST/application passwords, cron, CLI,
  XML-RPC och andra egna autentiseringsflöden får inget generellt tokenkrav.
  En begäran som faktiskt utlöser ett skyddat native-formulär omfattas ändå;
  AJAX-/API-flaggor är inget undantag för samma handler.
- Endast begäran om resetmejl skyddas. Slutligt lösenordsbyte via mejllänk,
  registrering, `wp_login_form()`, egna AJAX-/headless-/blockbaserade formulär,
  SSO och egna login-URL:er som ersätter core-handlern ingår inte.
- Script köas endast när en skyddad widget eller adminprovet renderas.
  För WooCommerce krävs standardhooks och `wp_footer()` i temat.
- WooCommerce kräver samma hostname i `home_url()` och `site_url()` i denna
  version. Aktivering med separata värdar nekas; stöd kräver separat provflöde.
- Kända aktiva Turnstile-tillägg identifieras via plugin-sökvägar (även
  nätverksaktiva): `simple-cloudflare-turnstile`, `wp-cloudflare-turnstile`,
  `simple-turnstile`. Aktivering nekas vid konflikt. Om en konflikt tillkommer
  efter aktivering blockeras formulär med tydlig adminstatus, utan extra
  Siteverify-anrop eller widget. Administratören väljer en ansvarig leverantör.
  Det finns ingen automatisk överlämning eller garanti att alla leverantörer
  upptäcks. Inventera även säkerhetspaket, tema och MU-plugins före aktivering.

För egna/MU-integrationer ska driftansvarig utöka konfliktlistan:

```php
add_filter( 'agoodmonitor_turnstile_conflicts', function ( array $found ): array {
    if ( defined( 'OUR_EXISTING_CAPTCHA_ENABLED' ) && OUR_EXISTING_CAPTCHA_ENABLED ) {
        $found[] = 'existing-mu-captcha';
    }
    return $found;
} );
```

Modulen skriver aldrig token, secret eller Cloudflares råsvar till HTML, loggar
eller health reports. Health reporter har ingen koppling till inställningen.
HTTP-debug-/APM-tillägg kan avlyssna WordPress HTTP-API: undanta eller maskera
Siteverify-requestens `secret` och `response` i sådan extern instrumentering.

## 2FA och begränsning av upprepade försök

Pilotförslag: etablerade **Two Factor** med TOTP och engångsreservkoder, under
förutsättning att sajten inte redan har en fungerande lösning. Dess separata
`validate_2fa`/`revalidate_2fa`-steg får inget nytt Turnstile-krav. Inget eget
2FA-system byggs. Tvingande utrullning per roll kräver separat beslut; Two Factor
har inte ett inbyggt UI för generell rollpolicy. Andra 2FA-tillägg är inte
kompatibilitetsverifierade i denna leverans.

Rekommendation för rate limiting: inventera först befintligt säkerhetstillägg
och hostingens begränsningar. Använd en befintlig, underhållen lösning om den
täcker både login och resetbegäranden; komplettera annars i webbserver/hosting.
Kontrollera betrodda proxy-IP:n, separata gränser för resetmejl och inloggning,
samt delade IP-adresser och risk för att en angripare låser ett offerkonto.
Välj ingen egen AGoodMonitor-modul förrän ett verifierat behov återstår.
Turnstile ersätter inte dessa gränser. Ingen rate limiting, WAF, malware-
skanning eller DNS-förändring implementeras här.

## Verifiering och pilotstatus

Se [tests/README.md](../tests/README.md). Lokalt verifierat med disponibel
WordPress 7.1.3, PHP 8.1.23, WooCommerce 11.2.1 och Two Factor 0.17.0:
65 integrationsassertioner och 15 HTTP-assertioner med kompletta flöden för login,
resetbegäran, TOTP och reservkod. Siteverify är simulerat och all e-post fångas.
Äldre stödda WP/PHP-versioner och andra 2FA-tillägg har inte körts.

På `agoodsite.se` installerades och aktiverades pilotversionen 2026-10-17.
**Turnstile är aktiverat** efter riktigt Cloudflare-konfigurationsprov.
Färsk privat fil-/databasbackup, filåtkomst och serverstyrt nödstopp är verifierade.
Separat testkonto finns. Direkta login/reset-POST med saknad eller ogiltig token
nekas med 403; riktig giltig verifiering tillåter resetmejl till testbrevlådan.
Se [pilotprotokollet](turnstile-agoodsite-pilot.md) för omfattning och begränsningar.
Lyckad inloggning med testkontots nya lösenord och pilotens 2FA/reservkoder
återstår. Begärt sparande av nycklarna i 1Password inväntar upplåsning av appen.
Ingen installation har gjorts
på det tidigare pilotmålet agoodid2026.agoodsite.se eller på kundsajter. En godkänd
lokal suite innebär inte att pilotsajten är verifierad.

## Officiella källor (kontrollerade 2026-10-16)

- [Cloudflare: servervalidering, engångstoken och hostname/action](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
- [Cloudflare: widget-rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/)
- [Cloudflare: testnycklar](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
- [Cloudflare: CSP](https://developers.cloudflare.com/turnstile/reference/content-security-policy/)
- [WordPress: login_form_{$action}](https://developer.wordpress.org/reference/hooks/login_form_action/)
- [WordPress: wp_remote_post](https://developer.wordpress.org/reference/functions/wp_remote_post/)
- [WordPress core: wp-login.php](https://github.com/WordPress/WordPress/blob/master/wp-login.php)
- [WooCommerce: standardhandlers](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-form-handler.php)
- [Two Factor: officiell tilläggssida](https://wordpress.org/plugins/two-factor/)
