# nv5 — server (env-nv5)

PHP-sync for **admin**, **sis**, **reise** og **shared** på `nv5.haatetepe.no`.

GitHub-repo: `blepman/nv5` (privat). Sync og oppsett krever `NV5_GITHUB_TOKEN` i `env/env-nv5/config.php`.

## Engangs-oppsett (anbefalt)

1. **Omdøp repo** til `nv5` og sett det **privat** på GitHub (Settings → General).
2. Opprett en **GitHub PAT** med read på repoet.
3. Last opp **`nv5-init.php`** fra `server`-branchen til **site root** (`www/nv5/` — mappen kan være tom).
4. Åpne **`https://nv5.haatetepe.no/nv5-init.php`**
5. Fyll inn **GitHub-token**, **admin-bruker** og **passord** — scriptet henter kode og oppretter `env/env-nv5/`.
6. Ved suksess **sletter `nv5-init.php` seg selv** fra serveren.

`nv5-init.php` ligger i repoet under `server`-branchen — last ned fra GitHub (ikke raw-URL; repoet er privat).

**Eldre alternativ:** `index-initial.php` (uten passordskjema — må slettes manuelt etter bruk).

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

## Host-layout (haatetepe.no / env-nv5)

```
(konto-roten)/
├── www/                         # webroot for haatetepe.no
│   └── nv5/                     # document root for nv5.haatetepe.no
│       ├── admin/
│       ├── sis/
│       ├── reise/
│       └── shared/
└── env/
    └── env-nv5/                 # secrets for nv5-subdomenet
        └── config.php           # admin-bruker/passord, GitHub-token, sync-nøkkel, …
        # valgfritt senere: admin/config.php, sis/config.php (overstyrer rot)
```

PHP finner `env/env-nv5` ved å gå opp til `www/` og deretter ett hakk til siden (`../env/env-nv5`). **Ikke** `www/env/…` og **ikke** `www/nv5/env/…`.

`config.php` opprettes automatisk ved sync/admin hvis den mangler. Eksisterende fil overskrives ikke.

Hvis auto-sti feiler: sett **`NV5_ENV_DIR`** til absolutt sti i host-miljøet.

PHP leser i rekkefølge: `getenv()` → `env/env-nv5/config.php` (+ valgfri `env/env-nv5/{app}/config.php` som overstyrer) → enkeltfil i `env/env-nv5/` → state-mappe (fallback).

## State og sikkerhet

- State/lock i system-temp (`sys_get_temp_dir()/nv5-sis-…`)
- Rot-`.htaccess` slår av mappevisning i site root; `nv5-lib/` er ikke web-tilgjengelig
- `/admin/` krever HTTP Basic Auth når `NV5_ADMIN_PASSWORD` er satt i `env/env-nv5/config.php` (eller env)
- Alternativ uten env-mappe: skriv passordet til `admin-password` i state-mappen (utenfor webroot)
- `?sync=server` kan kreve nøkkel (`NV5_SYNC_SERVER_KEY` i `env/env-nv5/` eller `sync-server-secret` i state)
- Ved hyppig sync: legg `NV5_GITHUB_TOKEN` i `config.php` (GitHub rate limit uten token)
- Rate limit per IP på tvungen sync
- Audit: `sync-audit.log` i state-mappen
- `nv5-init.php` og `index-initial.php` speiles **ikke** til webroot ved vanlig server-sync — last opp manuelt ved oppsett; `nv5-init.php` slettes automatisk ved suksess

Trenger PHP med **curl** (eller `allow_url_fopen`) og **zip**.

## nginx

`nginx-nv5.conf` speiles til site root. Reload nginx etter endringer:

```bash
nginx -t && systemctl reload nginx
```

**Ikke** sett ekstra CSP i nginx for HTML — PHP sender CSP.
