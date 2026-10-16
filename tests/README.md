# Isolerade Turnstile-tester

Kräver PHP, Python 3, en **disponibel lokal** WordPress-installation och separat
databas. Inga Composer-/npm-beroenden. Använd aldrig en kunddatabas eller
pilotsajt för dessa automatiserade fixtures; de simulerar Cloudflare-svar.

Installera WordPress med tabellprefix `agm_test_` och följande konfiguration:

```php
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HOME', 'http://127.0.0.1:8899' );
define( 'WP_SITEURL', 'http://127.0.0.1:8899' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
```

Blockera all mail redan under installationen med en lokal mailsink eller
`pre_wp_mail => __return_true`. Använd endast `example.invalid`-adresser.
Installera och aktivera officiella WooCommerce och Two Factor i denna miljö,
och kör deras installationsrutiner, inklusive `WC_Install::install()`.
Starta inte ordinarie AGoodMonitor/updater i testinstallationen.

## Integration mot riktig WordPress

Kör utan `http-fixture.php` som MU-plugin:

```sh
AGOODMONITOR_TEST_WP=/absolute/disposable-wordpress php tests/turnstile-integration.php
```

Suiten kräver `local` och exakt `agm_test_` före ändringar. Den använder riktiga
WP hooks, databas, autentisering och resetfunktioner, riktiga WC/TOTP/backup-
providers samt simulerar Siteverify via `pre_http_request`. Inga riktiga
verifieringsanrop görs, all mail fångas och okända HTTP-anrop nekas. Den täcker
avstängt läge, saknad/felaktig/utgången/återanvänd token, hostname/action,
HTTP/JSON/timeout, nonce/capability, konfigurationsprov/aktivering/nyckelbyte,
WooCommerce-tillval, återställningskonstant och secret i HTML/autoload.

## Riktiga HTTP-flöden på localhost

1. Kopiera `http-fixture.php` till testinstallationens `wp-content/mu-plugins/`.
   Definiera `AGOODMONITOR_TEST_PLUGIN` som repo-katalogens absoluta sökväg i
   testinstallationens `wp-config.php` före WP-laddningen. Fixturen får aldrig
   paketeras i en release eller installeras på en nåbar sajt.
2. Förbered disponibla konton, 2FA och WC-kontosidan:

   ```sh
   export AGOODMONITOR_TEST_WP=/absolute/disposable-wordpress
   export AGOODMONITOR_TEST_CODES=/tmp/agm-test-codes.json
   php tests/prepare-http.php
   ```

3. Starta separat `php -S 127.0.0.1:8899 -t "$AGOODMONITOR_TEST_WP"`.
4. Kör `python3 tests/turnstile-http.py` med samma `AGOODMONITOR_TEST_CODES`.
   Testet är låst till localhost. Det testar riktiga POSTs och cookies, WC-
   formulärens nonces och redirects, TOTP-inloggning och reservkodsinloggning.
   Lösenorden är offentliga testlösenord, bara för den disponibla installationen.
5. Stoppa PHP-servern/databasen och radera den disponibla installationen och
   kodfilen efter test. Kör `prepare-http.php` igen inför nästa HTTP-körning:
   TOTP och reservkoder är engångskoder.

Körda versioner: WP 7.1.3 / PHP 8.1.23 / WC 11.2.1 / Two Factor 0.17.0.
Siteverify-simulering verifierar vårt kontrakt och hantering av svar; den är
inget test av Cloudflares faktiska nätverk eller browser-challenge. Följ därför
även den manuella pilotchecklistan i [docs/turnstile.md](../docs/turnstile.md).
