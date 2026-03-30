# CLAUDE.md — AGoodMonitor WP

## Vad är det här?

WordPress-plugin som körs på alla AGoodId-kundsajter. Det gör två saker:

1. **Health reporting** — Samlar in data om WP-core, plugins, tema och Site Health och skickar till AGoodMember API varje timme.
2. **Security hardening** — Site-wide härdning (HTTP-headers, fingerprinting, attackyta) som gäller oavsett aktivt tema.

Pluginet är alltid aktivt och ska inte konkurrera med temats ansvar. Temats äger output-specifik säkerhet (escaping, nonces, capability checks). Pluginet äger det som ska gälla hela sajten oavsett tema.

---

## Filstruktur

```
agoodmonitor.php              Plugin-huvud — defines, require_once, instansiering
uninstall.php                 Städar options, transients, cron, tabell och .htaccess vid avinstallation
inc/
  class-hardening.php         Security hardening (HTTP-headers, fingerprinting, XML-RPC, m.m.)
  class-health-reporter.php   Hälsoinsamling, cron-schemaläggning, admin UI, AJAX
  class-link-monitor.php      Passiv 404/redirect-loggning, DB-tabell, admin UI
  github-updater.php          Auto-uppdatering via GitHub Releases
sprints/
  doing/                      Aktiva sprints (sprint-X.md = spec + implementation guide)
```

---

## Arkitektoniska val att känna till

### Konfiguration via filters
Alla härdningsåtgärder är avstängbara via `apply_filters()`. Namnkonvention: `agoodmonitor_{åtgärd}` (bool). Exempel: `agoodmonitor_disable_xmlrpc`, `agoodmonitor_strip_version_query`. Inga settings i databasen för härdning — filters i `functions.php` räcker.

### GitHub-baserade auto-uppdateringar
`inc/github-updater.php` kopplar in i WordPress uppdateringssystem. WP-admin visar en vanlig uppdateringsnotis när en ny GitHub Release taggas. Versionen i plugin-headern (`Version: X.Y.Z` i `agoodmonitor.php`) måste matcha release-taggen (utan `v`-prefix). `AGOODMONITOR_VERSION`-konstanten definieras manuellt i plugin-headern — kom ihåg att uppdatera den vid varje release.

### Cron-schemaläggning
Health reporter schemaläggs via `wp_schedule_event` i `init`-hooken. Cron ställs bara in om API-nyckeln är konfigurerad. Plugin-avaktivering rensar inte cron — det görs i `register_deactivation_hook` om det läggs till.

### `.htaccess` i uploads
Skapas på `admin_init` (en gång), bara på Apache (kontroll via `SERVER_SOFTWARE`). Nginx ignorerar `.htaccess` — skriv aldrig filen på Nginx-servrar. Logg på `error_log` om skrivningen misslyckas.

---

## WordPress-konventioner

- PHP 8.0+ — typdeklarationer på alla funktioner
- WordPress Coding Standards (WPCS) — tabs, snake_case, `esc_*`/`sanitize_*` på all input/output
- `phpcs:ignore`-kommentarer kräver alltid motivering i kommentar bredvid
- Inga externa beroenden, ingen Composer, ingen JS-bundler
- Inline JS (som i admin UI) är acceptabelt för enstaka enkla interaktioner
- `wp_remote_get/post` för HTTP — aldrig `file_get_contents` med URL

---

## Kända begränsningar

| Begränsning | Detalj |
|-------------|--------|
| `.htaccess` uploads-skydd | Fungerar bara på Apache — tyst no-op på Nginx |
| `/?author=N` enumeration | Täcks inte av user endpoint-filtret — känd lucka, dokumenterad i koden |
| `activate_plugin()` i `after_install` | Återaktiverar pluginet efter uppdatering även om det var deaktiverat |

---

## Hur man gör en release

1. Uppdatera `Version:` i plugin-headern i `agoodmonitor.php`
2. Uppdatera `AGOODMONITOR_VERSION`-konstanten till samma värde
3. Commit + push till `main`
4. Skapa GitHub Release med tag `vX.Y.Z` (exakt matchning mot version i headern, utan `v`)
5. Bifoga en ZIP-fil med plugin-katalogen som release asset (annars faller `github-updater.php` tillbaka på GitHubs automatiska zipball)
6. WP-sajter visar uppdateringsnotis vid nästa update-check

---

## Aktiva sprints

| Sprint | Fil | Status |
|--------|-----|--------|
| Security Hardening | `sprints/done/sprint-security-hardening.md` | Klar — releasad i v1.1.0 |
| Link Monitoring | `sprints/done/sprint-link-monitoring.md` | Klar — ej releasad ännu (väntar på v1.2.0) |

---

## Testning

Ingen automatiserad testsuite. Manuell verifiering per sprint — se respektive sprint-dokument för checklistor.

Snabbkoll efter förändring i `class-hardening.php`:
- `curl -I https://sajt.se` — verifiera att `X-Powered-By` är borta och att security headers finns
- `curl https://sajt.se/wp-json/wp/v2/users` som ej inloggad — ska returnera 401/403
- `curl https://sajt.se/xmlrpc.php` — ska returnera 403
