# Nákupní seznamy – Semestrálka Brož

Třívrstvá webová aplikace pro správu uživatelských nákupních seznamů provozovaná v kontejnerovém prostředí Docker.

## Architektura aplikace

Aplikace se skládá ze **3 izolovaných Docker kontejnerů** definovaných v souboru `docker-compose.yml`:

* **`postgresqldb`** – Relační databáze PostgreSQL (`postgres:18` z oficiálního Docker Hubu). Uchovává data o uživatelích, seznamech a položkách v trvalém diskovém svazku (`pgdata`), takže data přežijí i restart kontejnerů. Je připojená pouze do interní sítě `backend_net`.
* **`backend`** – Aplikační vrstva (PHP REST API z GitHub Container Registry `ghcr.io/mirekkkob/dce-backend:1.0`). Komunikuje s databází, při prvním spuštění automaticky vytvoří potřebné databázové tabulky a zajišťuje registraci, přihlašování i správu seznamů a položek. Propojuje sítě `frontend_net` a `backend_net`.
* **`frontend`** – Prezentační vrstva (webové rozhraní na serveru Apache z GitHub Container Registry `ghcr.io/mirekkkob/dce-frontend:1.0`). Zprostředkovává uživatelské rozhraní na portu `8080` a komunikuje výhradně s backendem přes síť `frontend_net`.

Pořadí spouštění služeb je řízeno pomocí `healthcheck` kontrol – backend čeká na plnou připravenost databáze a frontend startuje až ve chvíli, kdy je backend připraven přijímat požadavky.

---

## Návod na spuštění

1. Spusťte aplikaci **Docker Desktop** (případně Docker Engine) a vyčkejte, dokud neběží.
2. Naklonujte tento repozitář nebo otevřete složku se souborem `docker-compose.yml` v terminálu (např. ve Visual Studio Code).
3. Stáhněte obrazy a spusťte kontejnery na pozadí:
   ```bash
   docker compose pull
   docker compose up -d
   ```
4. Stav spuštěných kontejnerů a dokončení jejich inicializace (`healthy`) můžete ověřit příkazem:
   ```bash
   docker compose ps
   ```

---

## Ověření funkčnosti

Po spuštění všech služeb otevřete v internetovém prohlížeči adresu **`http://localhost:8080`**, kde se zobrazí webové rozhraní aplikace:

1. **Registrace uživatele:** Vytvořte nový uživatelský účet zadáním uživatelského jména a hesla.
2. **Přihlášení:** Přihlaste se pod nově vytvořeným účtem – po přihlášení se odemkne správa nákupních seznamů.
3. **Vytvoření seznamu:** Zadejte název nového nákupního seznamu a vytvořte jej.
4. **Přidání položek:** Do vytvořeného seznamu přidejte libovolné položky k nákupu.
5. **Odstranění položek a seznamů:** Vyzkoušejte odebrání jednotlivé položky ze seznamu i smazání celého nákupního seznamu.

---

## Ukončení aplikace

Pro zastavení a odstranění běžících kontejnerů (se zachováním uložených dat v databázi) spusťte:
```bash
docker compose down
```