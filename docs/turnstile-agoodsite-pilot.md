# Första installation: agoodsite.se

Användaren har 2026-10-17 uttryckligen beställt installation på `agoodsite.se`.
Det ersätter det tidigare första testmålet `agoodid2026.agoodsite.se`.
Endast denna installation omfattas; ingen release till kundsajter eller
installation på Lorensbergs ingår.

## Kontrollerat

- `https://agoodsite.se/wp-admin/plugins.php` leder till sajtens egen
  WordPress-inloggning med titeln **AGoodSite**.
- Chrome har ingen tillgänglig administratörssession på denna sajt.
- Turnstile-implementationen finns i PR #4. Senast verifierat lokalt med 65
  integrationsassertioner och 15 riktiga HTTP-assertioner. Cloudflare simulerades;
  inga mejl skickades ut från testmiljön.
- Ingen ändring har gjorts på servern. Installerad AGoodMonitor-version,
  WordPress/PHP-version, 2FA, WooCommerce, CAPTCHA och auto-update-status är
  ännu inte inventerade.

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

Administratörsinloggning och backup-/serveråtkomst behövs för att inventera och
installera utan att skriva över okända ändringar. Riktig Cloudflare-verifiering
och testkonton återstår därefter. Ett färdigt ZIP-paket är inte en bekräftelse på
att installationen genomförts.
