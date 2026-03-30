# Sprint: Link Monitoring

## Bakgrund

`agoodsite-fse` har en aktiv länkkontroll (`inc/link-checker.php`) som crawlar publicerat innehåll via WP-Cron. Problemet: aktiv crawling från samma server som betjänar trafik är resurskrävande, kan timeout:a, och blockas av CDN-lager som returnerar 200 på borttagna sidor.

Bättre modell: **passiv detection** — logga fel som faktiskt inträffar under normal trafik och rapportera aggregerat till AGoodMember i den befintliga timrapporten. Noll extra HTTP-requests, noll extra server-load.

## Scope

Två komplementära sources:

1. **404-loggning** — varje gång WordPress genererar en 404 loggas URL:en + referrer
2. **Redirect-loggning** — varje `wp_redirect` med 301/302 loggas (intern länk som bör uppdateras)

Insamlad data skickas med i `send_health_report()` och lagras i AGoodMember. Adminsida i pluginet visar de senaste felen per sajt.

Aktiv crawling av externa länkar lämnas till externa verktyg (Screaming Frog, Ahrefs). Det är ett annat problem som kräver en annan lösning.

---

## Datamodell

Lagras i en dedikerad DB-tabell för att undvika wp_options-bloat:

```sql
CREATE TABLE {prefix}agoodmonitor_link_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url         VARCHAR(2083) NOT NULL,
    referrer    VARCHAR(2083) DEFAULT '',
    type        ENUM('404', 'redirect') NOT NULL,
    redirect_to VARCHAR(2083) DEFAULT '',  -- bara för type=redirect
    status_code SMALLINT UNSIGNED NOT NULL,
    hits        INT UNSIGNED DEFAULT 1,
    first_seen  DATETIME NOT NULL,
    last_seen   DATETIME NOT NULL,
    INDEX (type, last_seen),
    UNIQUE KEY url_type (url(190), type)
) CHARACTER SET utf8mb4;
```

`hits`-kolumnen aggregerar — samma URL ökar räknaren istället för att skapa nya rader. Det håller tabellen liten även på trafiktunga sajter.

Tabell skapas vid plugin-aktivering via `register_activation_hook`.

---

## Filer att skapa/ändra

| Fil | Åtgärd |
|-----|--------|
| `inc/class-link-monitor.php` | Ny fil — all link monitoring-logik |
| `inc/class-health-reporter.php` | Lägg till `link_errors` i `collect_health_data()` |
| `agoodmonitor.php` | `require_once` + aktiverings-hook för DB-tabell |

---

## `inc/class-link-monitor.php`

### Hooks

```php
add_action('template_redirect', [$this, 'log_404']);        // 404-detection
add_filter('wp_redirect',       [$this, 'log_redirect'], 10, 2); // redirect-detection
add_action('admin_menu',        [$this, 'add_admin_page']);
```

### 404-loggning

```php
public function log_404(): void {
    if (!is_404()) return;

    $url      = home_url(add_query_arg([]));  // saniterad current URL
    $referrer = isset($_SERVER['HTTP_REFERER'])
        ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
        : '';

    // Ignorera: wp-admin, REST API, statiska assets, bots utan referrer
    if ($this->should_ignore($url, $referrer)) return;

    $this->upsert_log($url, $referrer, '404', '', 404);
}
```

### Redirect-loggning

Loggar bara interna redirects (samma domän som source) — externa redirects är inte vår sak att hantera.

```php
public function log_redirect(string $location, int $status): string {
    if (!in_array($status, [301, 302], true)) return $location;

    $current_url = home_url(add_query_arg([]));
    $home        = home_url();

    // Bara om source är intern
    if (strpos($current_url, $home) !== 0) return $location;

    $this->upsert_log($current_url, '', 'redirect', $location, $status);
    return $location; // alltid returnera oförändrad
}
```

### Ignorera-lista

```php
private function should_ignore(string $url, string $referrer): bool {
    $ignore_patterns = apply_filters('agoodmonitor_link_ignore_patterns', [
        '/wp-admin/',
        '/wp-json/',
        '/wp-cron',
        '.php',
        '/feed/',
        '/favicon',
        '/robots.txt',
        '/sitemap',
    ]);

    foreach ($ignore_patterns as $pattern) {
        if (strpos($url, $pattern) !== false) return true;
    }

    // Ignorera 404:or utan referrer (direkt-trafik, bots) — minskar brus
    if (empty($referrer) && apply_filters('agoodmonitor_ignore_direct_404', true)) {
        return true;
    }

    return false;
}
```

> **Designval:** Att ignorera 404:or utan referrer reducerar drastiskt brus från bots som probar `/wp-login.php`, `/.env`, `/xmlrpc.php` etc. Nackdel: vi missar broken links som besökare når direkt (bokmärken). Filterbar per sajt.

### DB-upsert

```php
private function upsert_log(string $url, string $referrer, string $type, string $redirect_to, int $status): void {
    global $wpdb;
    $table = $wpdb->prefix . 'agoodmonitor_link_log';
    $now   = current_time('mysql');

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (url, referrer, type, redirect_to, status_code, hits, first_seen, last_seen)
         VALUES (%s, %s, %s, %s, %d, 1, %s, %s)
         ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
        $url, $referrer, $type, $redirect_to, $status, $now, $now, $now
    ));
}
```

### Rensning

Rader äldre än 90 dagar rensas veckovis via WP-Cron. Filterbar: `agoodmonitor_link_log_retention_days`.

---

## Integration med health report

I `collect_health_data()`:

```php
'link_errors' => $this->get_recent_link_errors(),
```

```php
private function get_recent_link_errors(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'agoodmonitor_link_log';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT url, referrer, type, redirect_to, status_code, hits, last_seen
         FROM {$table}
         WHERE last_seen > %s
         ORDER BY hits DESC
         LIMIT 50",
        gmdate('Y-m-d H:i:s', strtotime('-7 days'))
    ), ARRAY_A);
}
```

AGoodMember tar emot `link_errors`-arrayen och kan visa det i sitt monitoring-dashboard per sajt.

---

## Admin-sida

Enkel lista under **Inställningar > AGoodMonitor > Länkfel** (ny tab i befintlig sida):

- Kolumner: URL, Typ (404/redirect), Träffar, Referrer, Senast sedd
- Sorterbar på hits och datum
- Knapp: "Rensa logg"
- Länk direkt till Redigera-sidan för inlägget som innehåller den brutna länken (om referrer är intern)

---

## Vad som inte ingår

| Åtgärd | Varför inte |
|--------|-------------|
| Aktiv crawling av externa länkar | Resurskrävande, gör bättre externt (Screaming Frog) |
| E-postnotifiering vid nya fel | Fas 2 — AGoodMember kan hantera notifieringar centralt |
| Automatisk länkfixning | För riskabelt att automatisera |
| Länkkontroll i editor (realtid) | Plugin-territory: kräver REST-anrop per länk vid save |

---

## Verifiering

1. Besök en 404-sida med `?ref=https://sajt.se/test` — rad dyker upp i DB
2. Besök en gammal URL som har en 301-redirect — loggas som `redirect`
3. Besök `/.env`, `/wp-login.php` (utan referrer) — loggas **inte** (should_ignore)
4. Vänta på timrapporten (eller trigga manuellt) — `link_errors` finns i payload till AGoodMember
5. Admin-sidan visar insamlade fel med korrekt hit-räkning

---

## Storlek

~150 rader PHP. En DB-tabell. Ingen JS, inga externa beroenden.
