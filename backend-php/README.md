# MarcinCoach v2 Backend

Backend MarcinCoach v2 jest aplikacja Laravel/PHP obslugujaca API pod `/api/*`.

## Zakres

- auth i custom session token,
- profil uzytkownika i onboarding data-first,
- import treningow TCX oraz normalizacja aktywnosci z integracji,
- analytics, training signals, training context, training adjustments,
- weekly plan, block context, plan memory i alerty,
- AI plan, AI insights i feedback-v2 AI,
- integracje Strava OAuth i Garmin connector.

## Uruchomienie lokalne

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Testy i diagnostyka:

```powershell
php artisan test
php artisan route:list --path=api
```

## Najwazniejsze zmienne env

```text
APP_ENV=
APP_KEY=
APP_URL=
DB_CONNECTION=sqlite

OPENAI_API_KEY=
AI_INSIGHTS_PROVIDER=stub
AI_INSIGHTS_MODEL=gpt-5-mini
AI_PLAN_PROVIDER=stub
AI_PLAN_MODEL=gpt-5
AI_FEEDBACK_PROVIDER=stub
AI_FEEDBACK_MODEL=gpt-4o-mini
AI_DAILY_LIMIT=20

STRAVA_CLIENT_ID=
STRAVA_CLIENT_SECRET=
STRAVA_REDIRECT_URI=
STRAVA_SCOPES=activity:read_all,profile:read_all

GARMIN_CONNECTOR_BASE_URL=
GARMIN_CONNECTOR_API_KEY=
```

AI providery moga dzialac w trybie `stub`. Garmin wymaga zewnetrznego adaptera z `integrations/garmin-connector/`; bez `GARMIN_CONNECTOR_BASE_URL` endpointy Garmina zwroca blad konfiguracji.

## Struktura

- `app/Http/Controllers/Api/` - kontrolery API.
- `app/Services/` - logika domenowa i integracje.
- `app/Models/` - modele Eloquent.
- `database/migrations/` - schemat bazy.
- `routes/api.php` - publiczny kontrakt API.
- `tests/Feature/Api/` - testy kontraktowe API.
- `tests/Unit/` - testy serwisow domenowych.

## Produkcja

Backend jest deployowany przez Git remote `iqhost` do katalogu roboczego IQHost. Frontend nie jest budowany przez hook backendowy; deploy frontu odbywa sie osobno przez rootowy `deploy-front.ps1`.
