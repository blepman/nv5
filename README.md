# nv5-sis (env-nv5)

Mono-repo for NV5-appene på `nv5.haatetepe.no`.

| App | Live | Mappe |
|-----|------|-------|
| **Admin** (drift) | `/admin/` | [`admin/`](admin/) |
| **SIS** (sanntidstavle) | `/sis/` | [`sis/`](sis/) |
| **Reise** (planlegger) | `/reise/` | [`reise/`](reise/) |
| **Delt** | `/shared/` | [`shared/`](shared/) |

Plan for Reise: [`docs/REISE_PLAN.md`](docs/REISE_PLAN.md).

## Dokumentasjon

| Fil | For hvem | Innhold |
|-----|----------|---------|
| [README.md](README.md) (denne) | Drift / oppsett | Sync, branches, struktur |
| [AGENTS.md](AGENTS.md) | AI-agenter | Harde regler, scope, merge-vaner |
| [docs/PROJECT_KNOWLEDGE.md](docs/PROJECT_KNOWLEDGE.md) | Agenter + vedlikeholdere | UI-lover, Entur, anti-mønstre |
| [docs/REISE_PLAN.md](docs/REISE_PLAN.md) | Alle | Reiseplanlegger — plan og faser |

## Branch-modell

| Branch | Innhold |
|--------|---------|
| `main` | `admin/`, `sis/`, `reise/`, `shared/`, `docs/` |
| `server` | PHP-sync, nginx-conf for `/admin/`, `/sis/`, `/reise/`, `/shared/` |

Feature-branches: `cursor/<app>-<beskrivelse>-9451` eller `cursor/server-<beskrivelse>-9451`.

## Sync (live)

| URL | Effekt |
|-----|--------|
| `/admin/?sync=env` | Server (PHP) + `/shared/` fra GitHub |
| `/admin/?sync=server` | Kun PHP/nginx fra `server`-branch |
| `/admin/?sync=shared` | Kun `shared/` fra `main` |
| `/admin/?sync=sis` | Kun SIS-innhold |
| `/admin/?sync=reise` | Kun Reise-innhold |
| `/admin/?sync=all` | Miljø + SIS + Reise |
| `/sis/?sync=1` | Hent `sis/` fra `main` → `/sis/content/` |
| `/reise/?sync=1` | Hent `reise/` fra `main` → `/reise/content/` |

Delt kode lastes fra **`/shared/`** (ikke kopiert inn i hver app).

## Repo-struktur

```
admin/         Drift-UI (sync-knapper, lenker)
sis/           Tavle (index, js/site.js, css/kiosk.css, icons, …)
reise/         Planlegger
shared/        js/entur.js, js/util.js, css/tokens.css, fonts/
docs/          Kunnskap og planer
scripts/       dev_server.py, serve.sh
deploy/        Host-oppsett
```

## Lokal preview

```bash
./scripts/serve.sh 8080
```

- http://localhost:8080/admin/ — drift
- http://localhost:8080/sis/ — sanntidstavle
- http://localhost:8080/reise/ — reiseplanlegger
- http://localhost:8080/shared/ — delt kode (speiler prod)

Dev-serveren ruter URL-er som på prod; ingen `prepare-dev.sh` eller kopiering av `shared/`.

## SIS — kort

- Meny: Innstillinger, Legg til holdeplass, **Planlegg reise** → `/reise/`, Hent ny tavle
- Server/shared: **NV5 Admin** → `/admin/`
- Standard holdeplass: Tveita T kai `NSR:Quay:11309`
- Entur-klient: `haatetepe-nv5-sis`

Se [`sis/config.js`](sis/config.js) for defaults.

## Kiosk på iPhone

1. Åpne `/sis/` i Safari
2. Del → **Legg til på Hjem-skjerm**
