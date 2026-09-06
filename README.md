# nv5-sis (env-nv5)

Mono-repo for NV5-appene på `nv5.haatetepe.no`.

| App | Live | Mappe |
|-----|------|-------|
| **SIS** (sanntidstavle) | `/sis/` | [`sis/`](sis/) |
| **Reise** (planlegger) | `/reise/` | [`reise/`](reise/) |
| **Delt** | — | [`shared/`](shared/) |

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
| `main` | `sis/`, `reise/`, `shared/`, `docs/` |
| `server` | PHP-sync til `/sis/` (og senere `/reise/`) |

Feature-branches: `cursor/<app>-<beskrivelse>-9451` eller `cursor/server-<beskrivelse>-9451`.

## Sync (live)

| URL | Effekt |
|-----|--------|
| `?sync=main` | Hent `sis/` + `shared/` fra `main` → `/sis/content/` |
| `?sync=server` | Oppdater PHP fra `server`-branch |
| `?sync=both` | Begge |
| `?sync=reise` | *(kommer)* `reise/` + `shared/` → `/reise/content/` |

## Repo-struktur

```
sis/           Tavle (index, js/site.js, css/kiosk.css, icons, …)
reise/         Planlegger (under utvikling)
shared/        js/entur.js, js/util.js, css/tokens.css, fonts/
docs/          Kunnskap og planer
scripts/       prepare-dev.sh, serve.sh
deploy/reise/  Host-oppsett for /reise/
```

PHP på host kopierer `shared/` inn i `content/shared/` ved sync, så apper refererer til `shared/js/…` relativt til base-href.

## Lokal preview

```bash
./scripts/serve.sh 8080
```

- http://localhost:8080/sis/ — sanntidstavle
- http://localhost:8080/reise/ — reise (placeholder)

`prepare-dev.sh` kopierer `shared/` til `sis/shared/` og `reise/shared/` (gitignored).

## SIS — kort

- Meny: Innstillinger, Legg til holdeplass, **Planlegg reise** → `/reise/`, Hent ny tavle
- Standard holdeplass: Tveita T kai `NSR:Quay:11309`
- Entur-klient: `haatetepe-nv5-sis`

Se [`sis/config.js`](sis/config.js) for defaults.

## Kiosk på iPhone

1. Åpne `/sis/` i Safari
2. Del → **Legg til på Hjem-skjerm**
