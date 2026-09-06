# Plan: NV5 Reise (`/reise`)

Levende plan for reiseplanleggeren på `https://nv5.haatetepe.no/reise/`.  
SIS (sanntidstavle) forblir på `/sis/`. Dette dokumentet er **go-beslutning** og implementeringsplan.

**Status:** Godkjent — Scenario 3 (fullt spor `/reise`)  
**Miljø:** `env-nv5`  
**Sist oppdatert:** 2026-09-06

Relatert: [`PROJECT_KNOWLEDGE.md`](PROJECT_KNOWLEDGE.md) · [`README.md`](../README.md) · [`AGENTS.md`](../AGENTS.md)

---

## 1. Beslutning

| | |
|---|---|
| **Hva** | Egen reiseplanlegger på `/reise/` |
| **Hvorfor** | Gøy å bygge, eget produkt på eget domene — «fordi vi kan» er tilstrekkelig motivasjon |
| **SIS** | Urørt i formål; forblir kiosk/sanntidstavle på `/sis/` |
| **Scenario** | 3 — fullt spor med deploy, sync og lenker mellom appene |

Motargumenter (Entur finnes, vedlikehold, osv.) er **bevisst oversett** — prosjektet gjennomføres.

---

## 2. Brukere og plattformer

| | |
|---|---|
| **Hvem** | Eier, gjester, familie |
| **Enheter** | iPhone, iPad, Android, Windows-desktop, Mac |
| **Frekvens** | Daglig — verdt å bygge og vedlikeholde |
| **PWA hjemskjerm** | Ja, etter hvert (ikke kritisk i v1) |

Design og testing skal verifiseres på alle plattformene over — ikke bare desktop.

---

## 3. Miljø og mappestruktur (`env-nv5`)

Alt under `env-nv5` organiseres i **app-mapper** for oversikt og gjenbruk:

```
env-nv5/                          # Cursor Cloud-miljø / mono-repo-roten
├── sis/                          # nv5-sis (sanntidstavle) — app + ev. env-spesifikke filer
├── reise/                        # nv5-reise (planlegger) — app + ev. env-spesifikke filer
├── shared/                       # Delte moduler (Entur, tokens, utils)
└── docs/                         # Felles dokumentasjon (denne planen, kunnskap)
```

### Deploy-mapping (live)

| Kilde (repo) | Live URL | PHP sync-mål |
|---|---|---|
| `sis/` | `https://nv5.haatetepe.no/sis/` | `/sis/content/` |
| `reise/` | `https://nv5.haatetepe.no/reise/` | `/reise/content/` |
| `shared/` | Inkludert i begge apper ved sync (eller `/shared/` statisk) | Avklares i `server`-PR |

### Migrering av dagens SIS

Dagens `main`-branch har filer i **repo-roten**. Første strukturelle jobb:

1. Flytt tavle-filer til `sis/` (index, js, css, icons, manifest, config)
2. Oppdater `server`-branch til å speile `sis/` → `/sis/content/`
3. Juster nginx/PHP base-href (uendret URL `/sis/` utad)

**Ingen funksjonell endring** — kun flytting og path-oppdateringer.

---

## 4. Delt kode (`shared/`)

Fremtidige apper (SIS, Reise, ev. mer) skal kunne gjenbruke felles kode uten kopiering.

### Foreslått innhold

| Fil/mappe | Innhold |
|---|---|
| `shared/js/entur.js` | GraphQL-klient, fetch, geocoder, `trip()` + avgangs-queries |
| `shared/js/util.js` | `escapeHtml`, timeout-fetch, evt. cookie-hjelpere |
| `shared/css/tokens.css` | Farger, fonter, `--accent`, mørk bakgrunn |
| `shared/fonts/` | IBM Plex (én kopi) |

### Konvensjoner

- App-spesifikk kode: `sis/js/site.js`, `reise/js/plan.js`
- App-spesifikk stil: `sis/css/kiosk.css`, `reise/css/reise.css` (importerer `tokens.css`)
- **Ikke** importere tavle-UI (`departure__*`, ticker) i reise
- Endring i `shared/` krever PR som vurderer **begge** apper

### Entur-klientnavn

| App | `ET-Client-Name` |
|---|---|
| SIS | `haatetepe-nv5-sis` (uendret) |
| Reise | `haatetepe-nv5-reise` |

---

## 5. v1 — Definition of Done

Alt under er **must-have** før v1 regnes som ferdig:

- [ ] **Fra** + **til** med søk (geocoder, som SIS holdeplasssøk)
- [ ] **«Reis nå»** som standard
- [ ] **2–3 reiseforslag** med varighet, antall bytter, avgang/ankomst
- [ ] **Etappliste** per forslag (linje, stopp, tider, situasjoner) — **uten kart**
- [ ] **Lenker** mellom `/sis/` og `/reise/`
- [ ] Responsivt på iPhone, iPad, Android, Win, Mac
- [ ] Live på `https://nv5.haatetepe.no/reise/` med sync fra GitHub

**Ikke i v1:** kart, PWA manifest/hjemskjerm (kan komme rett etter), favoritter, historikk.

---

## 6. Git og branches

| Branch | Rolle |
|---|---|
| `main` | Mono-repo: `sis/`, `reise/`, `shared/`, `docs/` |
| `server` | PHP-sync, nginx-conf for `/sis/` og `/reise/` |
| `reise` | *(valgfritt kortvarig)* feature-arbeid før merge til `main` |

### Feature-branches (Cloud Agent)

- `cursor/sis-<beskrivelse>-9451`
- `cursor/reise-<beskrivelse>-9451`
- `cursor/shared-<beskrivelse>-9451`
- `cursor/server-<beskrivelse>-9451`

### Sync-URLer (etter server-oppdatering)

| URL | Effekt |
|---|---|
| `?sync=sis` | Hent tavle fra `main` → `/sis/content/` |
| `?sync=reise` | Hent planlegger → `/reise/content/` |
| `?sync=server` | Oppdater PHP/nginx |
| `?sync=both` | Legacy: sis + server (beholdes) |
| `?sync=all` | sis + reise + server |

---

## 7. Implementeringsfaser

### Fase 0 — Struktur (før funksjon)

| # | Oppgave | Leveranse |
|---|---------|-----------|
| 0.1 | Flytt SIS-filer til `sis/` | Ingen bruker-synlig endring på `/sis/` |
| 0.2 | Opprett `shared/` med utskilt `entur.js` + `tokens.css` | SIS bygger på shared uten funksjonsendring |
| 0.3 | Opprett `reise/` med minimal «kommer snart»-side | Kan testes lokalt |
| 0.4 | Oppdater `server` + nginx for begge paths | `/reise/` svarer på nett |
| 0.5 | Dokumenter sync og mappestruktur i README | Drift forstått |

**Exit:** `/sis/` fungerer som før; `/reise/` eksisterer (tom eller placeholder).

---

### Fase 1 — Mini-planlegger (v1)

| # | Oppgave |
|---|---------|
| 1.1 | `shared/js/entur.js`: `planTrip(from, to, dateTime)` |
| 1.2 | `reise/js/plan.js`: søk fra/til, «Reis nå», resultatliste |
| 1.3 | `reise/js/plan.js`: etappvisning med linjefarger og situasjoner |
| 1.4 | `reise/css/reise.css`: eget layout, delte tokens |
| 1.5 | `reise/index.html` + `config.js` + `manifest.webmanifest` |
| 1.6 | Lenker SIS ↔ Reise i meny/footer |
| 1.7 | Test på alle plattformer (se §2) |

**Exit:** v1 Definition of Done (§5) er oppfylt.

---

### Fase 2 — Polering (etter v1, før Fase 4)

| # | Oppgave | Prioritet |
|---|---------|-----------|
| 2.1 | PWA: `manifest.webmanifest`, ikoner, «Legg til på Hjem-skjerm» | Høy |
| 2.2 | Forhåndsvalgt «fra» (sist brukt / Tveita T) | Medium |
| 2.3 | «Reis senere» (velg tidspunkt) | Medium |
| 2.4 | Tydeligere situasjonsvisning på etapper | Medium |
| 2.5 | Delbar lenke med forhåndsutfylt destinasjon | Lav |

---

### Fase 3 — Server og drift

Løpende ved endringer i `server`-branch:

- PHP speiler `sis/` og `reise/` korrekt
- Build-SHA i footer/meta per app
- nginx `nginx-reise-pwa.conf` (speil av sis-mønster)
- Cache-headers for shared assets

---

### Fase 4 — Veikart (implementeres etter hvert)

Planlagt utvidelse — **ikke** scope for v1, men designes inn fra start (utvidbar arkitektur).

| # | Funksjon | Merknad |
|---|----------|---------|
| 4.1 | **Kart** | Rute og stopp på kart (Leaflet eller tilsvarende) |
| 4.2 | **Favoritter** | Lagrede fra/til i `localStorage` (`nv5-reise-settings`) |
| 4.3 | **Historikk** | Siste N søk |
| 4.4 | **Filtre** | Transportmodus, rullestol, unngå bytte, sykkel som første/siste etappe |
| 4.5 | **«Reis senere» avansert** | Gjentakende reiser, ankomst-tid i stedet for avreise |
| 4.6 | **Kobling til SIS** | «Planlegg reise fra denne holdeplassen» med forhåndsutfylt fra |
| 4.7 | **Deling** | QR/lenke med full reisekontekst |
| 4.8 | **Offline/graceful** | Vis sist hentede forslag ved nettfeil |

Fase 4 prioriteres etter brukerfeedback etter v1 + Fase 2.

---

## 8. UI-prinsipper (Reise)

Gjenbruk **design-DNA** fra SIS, ikke kiosk-layout:

- Mørk bakgrunn, IBM Plex, linjefarger fra Entur
- Store touch-targets (iPhone/iPad)
- Ingen ticker, ingen badge-equalize, ingen featured-rader
- Etapper som vertikal tidslinje eller enkle kort
- Kompakt header; søk øverst

---

## 9. Agent-regler (tillegg)

- Reise-PR-er endrer **ikke** SIS-opførsel uten eksplisitt bestilling
- `shared/`-endringer: test begge apper
- Ikke merge Tognr/vognløp inn i reise uten eget bestill
- Oppdater denne planen ved fasefullføring eller scope-endring

---

## 10. Neste konkrete steg

1. **Fase 0.1–0.2:** Flytt SIS til `sis/`, trekk ut `shared/`
2. **Fase 0.3:** Scaffold `reise/` med placeholder
3. **Fase 0.4:** `server`-PR for `/reise/` deploy (krever host)
4. **Fase 1:** Implementer mini-planlegger

Start med Fase 0 når du gir grønt lys for koding.

---

## Endringslogg

| Dato | Endring |
|---|---|
| 2026-09-06 | Første godkjente plan: GO, env-nv5, sis/reise/shared, Scenario 3, Fase 4-veikart |
