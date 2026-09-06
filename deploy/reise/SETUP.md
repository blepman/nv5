# NV5 Reise — engangs-oppsett på host

Reise-appen (`reise/` + `shared/` fra `main`) skal serveres fra **`https://nv5.haatetepe.no/reise/`**.

## Status

- **Fase 0:** Placeholder på `reise/index.html` (mono-repo)
- **PHP-sync for `/reise/`:** kommer i egen `server`-PR (speil `reise/` + `shared/` → `/reise/content/`)

## Når PHP er klart

1. Last opp `reise/index.php` og `reise/.htaccess` fra `server`-branchen til `/reise/` på hosten (tilsvarende engangs-oppsett for `/sis/`).
2. Legg til nginx-location for `/reise/` (speil `nginx-sis-pwa.conf` → `nginx-reise-pwa.conf`).
3. Åpne `https://nv5.haatetepe.no/reise/?sync=reise` for første sync.

## Lokal preview

```bash
./scripts/serve.sh 8080
```

- Tavle: http://localhost:8080/sis/
- Reise: http://localhost:8080/reise/
