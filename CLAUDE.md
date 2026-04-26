# MarcinCoach v2 — instrukcje dla AI

## DEPLOY FRONTENDU — OBOWIĄZUJĄCA ZASADA

**Nigdy nie budować frontu na IQHost.** Środowisko hooka Git nie ma `npm`.

### Poprawny workflow (jedyna słuszna kolejność):

1. Zmiany w repo (lokalnie)
2. `npm run build` — lokalny build
3. `.\deploy-front.ps1` — wysyła `dist/*` na serwer przez SCP
4. Front serwowany z `public_html/` na IQHost
5. Hook Git (`post-receive`) NIE buduje frontu — tylko checkoutuje backend

### Skrypt deploy-front.ps1 (w korzeniu repo):

```powershell
scp -r .\dist\* host89998@h3.iqhs.eu:/home/host89998/domains/coach.host89998.iqhs.pl/public_html/
```

### Adresy:

- Frontend: https://coach.host89998.iqhs.pl
- Backend API: https://api.coach.host89998.iqhs.pl/api
- SSH/SCP: `host89998@h3.iqhs.eu`

### Czego NIE diagnozować gdy front nie działa:

API, CORS, onboarding, React state, Laravel healthcheck — te elementy były sprawdzone i działają.
Realny problem zawsze był: stary `dist/` nie trafiał do `public_html/`.

---

## STRUKTURA PROJEKTU

- `src/` — frontend React/Vite/TypeScript
- `backend-php/` — backend Laravel (PHP)
- `deploy-front.ps1` — skrypt deployu frontendu
- `docs/deploy/` — dokumentacja deployu

## HOSTING

- IQHost, serwer: `h3.iqhs.eu`, konto: `host89998`
- SSH key: `C:\Users\marci\.ssh\id_ed25519`
- Git remote `iqhost` → deployuje backend (Laravel)
- Git remote `origin` → GitHub
