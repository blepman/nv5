# nv5-sis — server (env-nv5)

PHP-sync for **admin**, **sis**, **reise** og **shared** på `nv5.haatetepe.no`.

## Engangs-oppsett (anbefalt)

1. Last opp **`index-initial.php`** fra denne branchen til **site root** (mappen som inneholder `sis/`).
2. Åpne **`https://nv5.haatetepe.no/index-initial.php?run=1`**
3. Inkluder `nginx-nv5.conf` i HTTPS-vhost og reload nginx (hvis ikke gjort).
4. **Slett** `index-initial.php` når alt er grønt.

Scriptet henter server-skjelett + alt innhold (shared, admin, SIS, Reise) fra GitHub.

Raw: https://raw.githubusercontent.com/blepman/nv5-sis/server/index-initial.php

## Manuelt oppsett (alternativ)

1. Last opp til site root: `nv5-lib/sync.php`, `admin/`, `sis/`, `reise/`, `shared/.htaccess`
2. Åpne `https://nv5.haatetepe.no/admin/?sync=env` og deretter `?sync=all`

## Sync-URLer

| URL | Effekt |
|-----|--------|
| `/admin/?sync=env` | Server (PHP) + `/shared/` |
| `/admin/?sync=server` | Kun PHP/nginx fra `server`-branch |
| `/admin/?sync=shared` | Kun `main/shared/` → `/shared/` |
| `/admin/?sync=admin` | Admin-UI fra `main/admin/` |
| `/admin/?sync=sis` | `main/sis/` → `/sis/content/` |
| `/admin/?sync=reise` | `main/reise/` → `/reise/content/` |
| `/admin/?sync=all` | Miljø + SIS + Reise |
| `/sis/?sync=1` | `main/sis/` → `/sis/content/` |
| `/reise/?sync=1` | `main/reise/` → `/reise/content/` |

SIS-innstillinger (cookie `nv5_github_interval`) styrer automatisk sjekk av **kun SIS**-innhold.

## State og sikkerhet

- State/lock i system-temp (`sys_get_temp_dir()/nv5-sis-…`)
- `?sync=server` kan kreve nøkkel (`NV5_SYNC_SERVER_KEY` eller `sync-server-secret` i state)
- Rate limit per IP på tvungen sync
- Audit: `sync-audit.log` i state-mappen
- `index-initial.php` speiles **ikke** til webroot ved vanlig server-sync — slett etter bruk

Trenger PHP med **curl** (eller `allow_url_fopen`) og **zip**.

## nginx

`nginx-nv5.conf` speiles til site root. Reload nginx etter endringer:

```bash
nginx -t && systemctl reload nginx
```

**Ikke** sett ekstra CSP i nginx for HTML — PHP sender CSP.
