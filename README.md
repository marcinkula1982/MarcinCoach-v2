# MarcinCoach v2

MarcinCoach v2 to aplikacja coachingowa dla biegaczy: frontend React/Vite komunikuje sie z backendem Laravel/PHP, ktory obsluguje profil uzytkownika, import treningow, sygnaly treningowe, plan tygodniowy, AI insights/plan oraz integracje sportowe.

## Aktywna struktura

- `src/` - frontend React + Vite + TypeScript.
- `backend-php/` - backend Laravel/PHP i testy PHPUnit.
- `integrations/garmin-connector/` - adapter FastAPI dla Garmin Connect (`python-garminconnect`, tryb `stub/live`).
- `docs/status.md` - wykonane funkcjonalnosci i aktualny status projektu.
- `docs/roadmap.md` - plan dalszych prac.
- `docs/integrations.md` - status integracji sportowych.
- `docs/deploy/frontend-iqhost-deploy.txt` - obowiazujacy deploy frontendu.
- `docs/archive/` - dokumenty historyczne; nie uzywac jako aktualnych instrukcji.

Legacy backend Node/Nest zostal usuniety z aktywnego repo. Historia pozostaje w git.

## Lokalny frontend

```powershell
npm install
npm run dev
```

Domyslny adres Vite to zwykle `http://localhost:5173`.

Produkcyjny build:

```powershell
npm run build
```

Adres API dla produkcji ustawiany jest przez `.env.production`:

```text
VITE_API_BASE_URL=https://api.coach.host89998.iqhs.pl/api
```

## Lokalny backend

```powershell
cd backend-php
composer install
php artisan migrate
php artisan test
php artisan route:list --path=api
```

Backend API lokalnie dziala pod `/api/*`; produkcyjnie pod:

```text
https://api.coach.host89998.iqhs.pl/api
```

## Deploy frontendu

Obowiazujacy workflow:

```powershell
npm run build
.\deploy-front.ps1
```

Nie budujemy frontu na IQHost. Hook Git na IQHost nie ma `npm` i nie jest miejscem buildu frontendu. Gotowy `dist/*` jest wysylany przez SCP do `public_html/`.

## Dokumentowanie zmian

Po zrealizowaniu funkcjonalnosci dopisz ja do `docs/status.md`, sekcja `Dziennik zrealizowanych funkcjonalnosci`.

Planowane prace trzymaj w `docs/roadmap.md`. Integracje sportowe aktualizuj w `docs/integrations.md`.
