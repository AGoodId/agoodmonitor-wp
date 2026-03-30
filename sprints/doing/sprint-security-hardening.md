# Sprint: Security Hardening

## Bakgrund

AGoodMonitor är aktiverat på alla AGoodId-sajter och är rätt ställe för WordPress-härdning som ska gälla oavsett vilket tema som är aktivt. Temat (`agoodsite-fse`) äger sin output-specifika säkerhet (escaping, nonces, capability checks) men site-wide WordPress-härdning tillhör ett alltid-aktivt plugin.

Härdningen är uppdelad i tre lager:

1. **HTTP-headers** — webbläsaren instrueras att begränsa vad sidan får göra
2. **WordPress fingerprinting** — minskar angriparens förmåga att identifiera stack
3. **Attackyta** — stänger funktioner som sällan används men ofta utnyttjas

## Scope

Allt implementeras i `inc/class-hardening.php`, instansieras från `agoodmonitor.php`. Varje åtgärd är avstängbar via `apply_filters()` — child themes och specialsajter kan stänga av enskilda delar utan att patcha pluginet.

---

## Fas 1 — HTTP Security Headers

**Fil:** `inc/class-hardening.php`

Hook: `send_headers` (körs tidigt, innan output)

```php
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
```

**Inte** Content-Security-Policy — för bräcklig att sätta i ett plugin utan full kontroll över vilka scripts/styles varje sajt laddar. Lämnas till Wordfence eller en dedikerad CSP-plugin.

Filter: `agoodmonitor_security_headers` — array med header-namn => värde. Returnera `false` för ett enskilt header för att stänga av det.

---

## Fas 2 — WordPress Fingerprinting

Hook: `init`

| Åtgärd | Vad som tas bort |
|--------|-----------------|
| `remove_action('wp_head', 'wp_generator')` | `<meta name="generator" content="WordPress X.Y">` |
| `remove_action('wp_head', 'wlwmanifest_link')` | Windows Live Writer manifest |
| `remove_action('wp_head', 'rsd_link')` | Really Simple Discovery |
| `add_filter('the_generator', '__return_empty_string')` | WP-version i RSS-feeds |
| `add_filter('wp_headers', ...)` | Ta bort `X-Powered-By` om PHP exponerar den |
| `add_filter('style_loader_src', ...)` | Ta bort `?ver=` från CSS-URL:er |
| `add_filter('script_loader_src', ...)` | Ta bort `?ver=` från JS-URL:er |

> Version-strippingen (`?ver=`) påverkar inte cache-busting — WordPress hanterar det internt. Den förhindrar att exakta plugin-versioner läses ut via publika URL:er.

Filter: `agoodmonitor_strip_version_query` (bool) — stäng av om du hanterar versioning på annat sätt.

---

## Fas 3 — Attackyta

### XML-RPC

XML-RPC är sällan aktivt använt men en vanlig vektor för brute force och DDoS (amplification via multicall).

```php
add_filter('xmlrpc_enabled', '__return_false');
```

Filter: `agoodmonitor_disable_xmlrpc` (bool, default true) — sätt till false om Jetpack eller annan plugin kräver XML-RPC.

### REST API user enumeration

`/wp-json/wp/v2/users` exponerar alla användarnamn publikt, vilket underlättar riktade lösenordsattacker.

```php
add_filter('rest_endpoints', function($endpoints) {
    if (!current_user_can('list_users')) {
        unset($endpoints['/wp/v2/users']);
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }
    return $endpoints;
});
```

Filter: `agoodmonitor_block_user_enumeration` (bool, default true).

### Skydda uploads-mappen från PHP-exekvering

Lägger till en `.htaccess` i `wp-content/uploads/` om den inte redan finns:

```apache
<FilesMatch "\.php$">
    Deny from all
</FilesMatch>
```

Körs på `admin_init` (en gång, kontrollerar om filen redan finns). Skyddar mot uppladdade PHP-filer som körs som kod.

Filter: `agoodmonitor_protect_uploads` (bool, default true).

---

## Admin UI (valfritt, fas 4)

En enkel sektion i den befintliga inställningssidan (`AGoodMonitor > Inställningar`) med checkboxar för att stänga av enskilda härdningsåtgärder per sajt. Lagras som `agoodmonitor_hardening_settings` i wp_options.

**Prioritet: låg.** Filterbaserad konfiguration via `functions.php` i child theme räcker för de flesta fall. UI tillför värde om kunder sköter sina egna sajter utan kodrättigheter.

---

## Vad som *inte* ingår

| Åtgärd | Varför inte |
|--------|-------------|
| 2FA | Plugin-territory: WP 2FA eller Wordfence |
| WAF / malware-scanning | Kräver nätverksnivå: Wordfence eller Sucuri |
| Backup | UpdraftPlus eller hosting |
| `DISALLOW_FILE_EDIT` | wp-config.php — en plugin kan inte skriva constants |
| Rate limiting på `/wp-login.php` | Wordfence — kräver persistent state cross-request |
| Content Security Policy | För sajt-specifik, kräver CSP-plugin med rätt konfiguration |

---

## Filer att skapa/ändra

| Fil | Åtgärd |
|-----|--------|
| `inc/class-hardening.php` | Ny fil — all härdningslogik |
| `agoodmonitor.php` | Lägg till `require_once` + `new AGoodMonitor_Hardening()` |

---

## Verifiering

1. Kontrollera headers med [securityheaders.com](https://securityheaders.com) — förväntat resultat: A eller B
2. `curl -I https://sajt.se` — verifiera att `X-Powered-By` är borta
3. `curl https://sajt.se/wp-json/wp/v2/users` som ej inloggad — ska returnera 401
4. `curl https://sajt.se/xmlrpc.php` — ska returnera 403
5. Ladda upp `test.php` till uploads-mappen och verifiera att den inte exekveras
6. Kontrollera att Jetpack (om aktivt) fortfarande fungerar — ställ annars in `agoodmonitor_disable_xmlrpc` till false

---

## Storlek

~120 rader PHP. Inga externa beroenden, ingen JS, ingen databas utöver den befintliga options-tabellen.
