# AGoodMonitor

WordPress-plugin för AGoodId-kundsajter. Rapporterar hälsodata till AGoodMember automatiskt och härdnar WordPress site-wide.

---

## Funktioner

### Health Reporting
Skickar automatiskt varje timme till AGoodMember API:

- WordPress core-version + tillgängliga uppdateringar
- PHP-version
- Alla installerade plugins — version, status (aktiv/inaktiv), tillgängliga uppdateringar
- Aktivt tema + versionsinfo
- Site Health-status (kritiska problem och rekommendationer)

### Link Monitoring
Passiv detection av länkfel under normal trafik — noll extra serverbelastning:

- **404-loggning** — varje 404 med känd referrer loggas med URL, referrer och träffräknare
- **Redirect-loggning** — interna 301/302-redirects loggas (interna länkar som bör uppdateras)
- Aggregerad data skickas med i timrapporten till AGoodMember
- Admin-sida under **Inställningar → AGoodMonitor Länkfel** med direktlänk till "Redigera inlägg" för interna referrers
- Automatisk rensning av rader äldre än 90 dagar

### Security Hardening
Site-wide härdning som gäller oavsett aktivt tema:

| Åtgärd | Effekt |
|--------|--------|
| `X-Content-Type-Options: nosniff` | Förhindrar MIME-sniffing |
| `X-Frame-Options: SAMEORIGIN` | Skyddar mot clickjacking |
| `Referrer-Policy: strict-origin-when-cross-origin` | Begränsar referrer-läckage |
| `Permissions-Policy` | Stänger av kamera, mikrofon, geolokalisering, betalningar |
| `X-Powered-By` borttagen | Exponerar inte PHP-version |
| `wp_generator` meta borttagen | Döljer WordPress-version från HTML |
| `wlwmanifest` + `rsd_link` borttagna | Tar bort oanvända discovery-endpoints |
| `?ver=` borttagen från assets | Döljer exakta plugin-versioner från publika URL:er |
| XML-RPC inaktiverat | Blockerar brute force och DDoS-amplifiering via xmlrpc.php |
| REST `/wp/v2/users` skyddad | Förhindrar användarnamns-enumeration för icke-inloggade |
| `.htaccess` i uploads | PHP-filer i uploads-mappen kan inte exekveras (Apache) |

---

## Installation

1. Ladda ned senaste ZIP från [Releases](https://github.com/AGoodId/agoodmonitor-wp/releases)
2. Gå till **Plugins > Lägg till nytt > Ladda upp plugin** i WordPress-admin
3. Ladda upp ZIP-filen och aktivera pluginet
4. Gå till **Inställningar > AGoodMonitor**
5. Ange API-nyckel från AGoodMember

Pluginet uppdaterar sig automatiskt när nya versioner släpps på GitHub — precis som ett plugin från WordPress.org.

---

## Inställningar

| Inställning | Beskrivning | Standard |
|-------------|-------------|---------|
| API-nyckel | Från AGoodMember — krävs för rapportering | — |
| API-URL | Ändra bara om du kör en egen instans | `https://www.agoodsport.se` |

---

## Härdning — Konfiguration

Alla åtgärder är aktiverade som standard och kan stängas av per sajt via `functions.php` i child theme eller i `wp-config.php`:

```php
// Stäng av specifika åtgärder
add_filter( 'agoodmonitor_disable_xmlrpc', '__return_false' );           // om Jetpack behöver XML-RPC
add_filter( 'agoodmonitor_block_user_enumeration', '__return_false' );   // om /wp-json/wp/v2/users behövs
add_filter( 'agoodmonitor_protect_uploads', '__return_false' );          // om .htaccess redan hanteras
add_filter( 'agoodmonitor_strip_version_query', '__return_false' );      // om du hanterar versioning på annat sätt

// Anpassa security headers
add_filter( 'agoodmonitor_security_headers', function ( $headers ) {
    $headers['X-Frame-Options'] = 'DENY';      // striktare än SAMEORIGIN
    unset( $headers['Permissions-Policy'] );   // ta bort ett specifikt header
    return $headers;
} );
```

---

## Härdning — Vad som inte ingår

Dessa kräver dedikerade plugins eller konfiguration utanför pluginets scope:

| Åtgärd | Rekommenderat verktyg |
|--------|----------------------|
| Tvåfaktorsautentisering (2FA) | [WP 2FA](https://wordpress.org/plugins/wp-2fa/) eller Wordfence |
| Web Application Firewall (WAF) | [Wordfence](https://wordpress.org/plugins/wordfence/) eller Sucuri |
| Malware-scanning | Wordfence eller Sucuri |
| Begränsa inloggningsförsök | Wordfence eller [Login LockDown](https://wordpress.org/plugins/login-lockdown/) |
| Regelbundna backuper | [UpdraftPlus](https://wordpress.org/plugins/updraftplus/) eller hostingbackup |
| Content Security Policy (CSP) | Wordfence Premium eller dedikerad CSP-plugin |
| `DISALLOW_FILE_EDIT` | `wp-config.php`: `define( 'DISALLOW_FILE_EDIT', true )` |
| `DISALLOW_FILE_MODS` | `wp-config.php`: `define( 'DISALLOW_FILE_MODS', true )` |
| Tvinga SSL i admin | `wp-config.php`: `define( 'FORCE_SSL_ADMIN', true )` |

---

## Rekommenderad wp-config.php på produktionssajter

```php
define( 'DISALLOW_FILE_EDIT', true );  // inaktivera fil-redigering i dashboarden
define( 'FORCE_SSL_ADMIN', true );     // tvinga HTTPS i admin (kräver SSL-certifikat)
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## Säkerhetsprioriteringsordning (TL;DR)

1. **2FA** på alla adminkonton
2. **Starka lösenord** — unikt per sajt, lösenordshanterare
3. **WAF** — Wordfence (gratisversionen räcker för de flesta)
4. **Automatiska uppdateringar** — WordPress core, plugins, teman
5. **Backup** — offsite, testad återställning
6. **`DISALLOW_FILE_EDIT`** i wp-config.php
7. **AGoodMonitor** — HTTP-headers, fingerprint-reducering, attackyta

---

## Krav

- WordPress 6.0+
- PHP 8.0+

---

## För utvecklare

### Göra en ny release

1. Uppdatera `Version:` i plugin-headern i `agoodmonitor.php`
2. Uppdatera `AGOODMONITOR_VERSION`-konstanten till samma värde
3. Commit + push till `main`
4. Skapa GitHub Release med tag `vX.Y.Z`
5. Bifoga en ZIP med plugin-katalogen som release asset

Befintliga installationer visar automatiskt en uppdateringsnotis i WP-admin.

### Privat GitHub-repo

Lägg till i `wp-config.php` på sajter som behöver autentiserat nedladdning:

```php
define( 'AGOODMONITOR_GITHUB_TOKEN', 'ghp_...' );
```
