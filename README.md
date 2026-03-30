# AGoodMonitor

WordPress plugin som skickar hälsodata (plugins, tema, core, Site Health) till AGoodMember automatiskt varje timme. Används på alla AGoodId-kundsajter.

Inkluderar WordPress security hardening — HTTP-headers, fingerprint-reducering och minskad attackyta.

---

## Funktioner

### Health Reporting
Rapporterar automatiskt varje timme till AGoodMember API:
- WordPress core-version + PHP-version
- Aktiva plugins + versioner
- Aktivt tema
- Site Health-status

### Security Hardening *(kommer i v1.1)*
Site-wide härdning som gäller oavsett aktivt tema:
- HTTP security headers
- WordPress fingerprint-reducering
- XML-RPC avstängt
- REST API user enumeration blockerat
- Uploads-mapp skyddad mot PHP-exekvering

---

## Installation

1. Ladda upp till `wp-content/plugins/agoodmonitor/`
2. Aktivera i WordPress admin
3. Gå till **Inställningar > AGoodMonitor**
4. Ange API-nyckel från AGoodMember

Pluginet uppdaterar sig automatiskt från GitHub.

---

## Inställningar

| Inställning | Beskrivning |
|-------------|-------------|
| API-nyckel | Från AGoodMember — krävs för rapportering |
| API-URL | Standardvärde: `https://www.agoodsport.se` |

---

## Security Hardening — Konfiguration

Alla härdningsåtgärder är aktiverade som standard och kan stängas av per sajt via `functions.php` i child theme eller i `wp-config.php`.

```php
// Stäng av en specifik åtgärd
add_filter('agoodmonitor_disable_xmlrpc', '__return_false');       // om Jetpack behöver XML-RPC
add_filter('agoodmonitor_block_user_enumeration', '__return_false'); // om /wp-json/wp/v2/users behövs
add_filter('agoodmonitor_protect_uploads', '__return_false');       // om uploads-htaccess redan hanteras

// Anpassa security headers
add_filter('agoodmonitor_security_headers', function($headers) {
    $headers['X-Frame-Options'] = 'DENY'; // striktare än SAMEORIGIN
    unset($headers['Permissions-Policy']); // ta bort ett specifikt header
    return $headers;
});
```

### Vad härdningen gör

| Åtgärd | Effekt |
|--------|--------|
| `X-Content-Type-Options: nosniff` | Förhindrar MIME-sniffing |
| `X-Frame-Options: SAMEORIGIN` | Skyddar mot clickjacking |
| `Referrer-Policy: strict-origin-when-cross-origin` | Begränsar referrer-läckage |
| `Permissions-Policy` | Stänger av kamera, mikrofon, geolokalisering |
| Ta bort `wp_generator` meta | Döljer WordPress-version från HTML |
| Ta bort `wlwmanifest` + `rsd_link` | Tar bort oanvända discovery-endpoints |
| Ta bort `?ver=` från assets | Döljer plugin-versioner från publika URL:er |
| XML-RPC inaktiverat | Blockerar brute force via xmlrpc.php |
| REST `/wp/v2/users` skyddad | Förhindrar användarnamns-enumeration |
| `.htaccess` i uploads | PHP-filer i uploads-mappen kan inte exekveras |

### Vad härdningen inte gör

Dessa kräver dedikerade plugins eller konfiguration utanför pluginets scope:

| Åtgärd | Rekommenderat verktyg |
|--------|----------------------|
| Tvåfaktorsautentisering (2FA) | [WP 2FA](https://wordpress.org/plugins/wp-2fa/) eller Wordfence |
| Web Application Firewall (WAF) | [Wordfence](https://wordpress.org/plugins/wordfence/) eller Sucuri |
| Malware-scanning | Wordfence eller Sucuri |
| Begränsa inloggningsförsök | Wordfence eller [Login LockDown](https://wordpress.org/plugins/login-lockdown/) |
| Regelbundna backuper | [UpdraftPlus](https://wordpress.org/plugins/updraftplus/) eller hostingbackup |
| Content Security Policy (CSP) | Wordfence Premium eller dedikerad CSP-plugin |
| `DISALLOW_FILE_EDIT` | `wp-config.php`: `define('DISALLOW_FILE_EDIT', true)` |
| `DISALLOW_FILE_MODS` | `wp-config.php`: `define('DISALLOW_FILE_MODS', true)` |
| Tvinga SSL i admin | `wp-config.php`: `define('FORCE_SSL_ADMIN', true)` |

---

## Rekommenderad säkerhetskonfiguration (wp-config.php)

Lägg till i `wp-config.php` på alla produktionssajter:

```php
// Inaktivera fil-redigering i dashboarden
define('DISALLOW_FILE_EDIT', true);

// Tvinga SSL i admin (kräver SSL-certifikat)
define('FORCE_SSL_ADMIN', true);

// Inaktivera felsökning i produktion
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);
```

---

## Prioriteringsordning — säkerhet (TL;DR)

1. **2FA** på alla adminkonton
2. **Starka lösenord** — unikt per sajt, lösenordshanterare
3. **WAF** — Wordfence (gratis räcker för de flesta)
4. **Automatiska uppdateringar** — WordPress core, plugins, teman
5. **Backup** — offsite, testad återställning
6. **`DISALLOW_FILE_EDIT`** i wp-config.php
7. **AGoodMonitor** — HTTP-headers + fingerprint-reducering + attackyta

Dölja `/wp-admin`-URL:en är låg prioritet och rekommenderas inte av Wordfence — prioritera punkterna ovan istället.

---

## Krav

- WordPress 6.0+
- PHP 8.0+
