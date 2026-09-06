# NV5 Reise — engangs-oppsett på host

Reise-appen (`reise/` fra `main`) serveres fra **`https://nv5.haatetepe.no/reise/`**.  
Delt kode lastes fra **`/shared/`** (synkes via Admin).

## Første gangs oppsett

1. Last opp fra `server`-branchen (eller kjør `/sis/?sync=both` én gang etter merge):
   - `admin/index.php`, `admin/.htaccess`
   - `nv5-lib/sync.php`
2. Åpne **`https://nv5.haatetepe.no/admin/?sync=env`** — henter PHP, shared og admin-UI.
3. Åpne **`https://nv5.haatetepe.no/reise/?sync=reise`** — henter planleggeren.

Legg til nginx-include fra `nginx-nv5.conf` (erstatter eldre `nginx-sis-pwa.conf`).

## Lokal preview

```bash
./scripts/serve.sh 8080
```

- Admin: http://localhost:8080/admin/
- Tavle: http://localhost:8080/sis/
- Reise: http://localhost:8080/reise/
