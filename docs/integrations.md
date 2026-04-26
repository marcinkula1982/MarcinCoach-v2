# MarcinCoach v2 - integracje sportowe

Status dokumentu: aktywne zrodlo prawdy dla integracji danych treningowych.

Ten plik jest jedynym miejscem w `docs/`, gdzie aktualizujemy decyzje i status integracji sportowych. Starsze dokumenty integracyjne zostaly przeniesione do `docs/archive/`.

## Zasada produktu

MarcinCoach buduje profil uzytkownika najpierw z realnych danych treningowych, a ankieta uzupelnia tylko informacje, ktorych backend nie moze wiarygodnie odczytac z aktywnosci.

Onboarding data-first:
- Strava,
- Garmin,
- pliki TCX/FIT/GPX,
- sciezka reczna, gdy uzytkownik nie ma danych,
- Polar i Suunto jako kolejne zrodla po stabilizacji MVP.

## Aktualny status

| Zrodlo | Status | Integracja | Uwagi |
|---|---|---|---|
| TCX upload | Wdrozony fallback | `POST /api/workouts/upload`, parser TCX w Laravel | FIT/GPX sa planowane, ale nie sa jeszcze aktywnym MVP. |
| Strava | Czesc backendowa wdrozona | OAuth2, token exchange, sync aktywnosci | Oficjalne API. Wymaga produkcyjnych credentials, smoke i pilnowania scope/prywatnosci. |
| Garmin | Wdrozony jako MVP wysokiego ryzyka | zewnetrzny connector `GARMIN_CONNECTOR_BASE_URL`, `python-garminconnect`, tryb `stub/live` | Nieoficjalna sciezka przez Garmin Connect. Akceptujemy ryzyko auth/MFA/rate limit/regulaminu; TCX upload zostaje fallbackiem. |
| Polar | Planowane | Polar AccessLink API | Oficjalna integracja po stabilizacji Strava/Garmin. |
| Suunto | Planowane | Suunto API Zone / partner program | Wymaga formalnosci partnerskich. |
| Apple Watch / Health | Poza backendowym MVP | HealthKit tylko przez aplikacje iOS/watchOS lub eksport plikow | Brak prostego backendowego logowania do Apple Health. |

## Minimalna probka danych

Dla pierwszego sensownego profilu:
- minimum: 6 treningow,
- lepiej: 10+ treningow,
- optymalnie: ostatnie 60-90 dni z limitem liczby aktywnosci.

Jesli danych jest za malo, backend nie udaje pewnosci. Profil i plan powinny dostac nizszy confidence i ostrozniejsze zalozenia.

## Dane, ktore backend powinien wyciagac

- czestotliwosc treningow,
- sredni kilometraz,
- najdluzszy trening,
- tempa easy i szybkich treningow,
- HR avg / max,
- drift HR,
- rozklad intensywnosci,
- tolerancje obciazenia,
- wstepne strefy robocze.

## Sync log

Kazda integracja zewnetrzna powinna zostawiac slady w `integration_sync_runs`:
- zrodlo danych,
- status synchronizacji,
- liczba pobranych aktywnosci,
- zakres dat,
- bledy autoryzacji,
- bledy parsowania,
- data ostatniej synchronizacji.

Dla Garmina od poczatku traktujemy ryzyko jako jawne: `unofficial_connector`.

## Linki referencyjne

- Strava API: https://developers.strava.com/docs/
- Garmin Activity API: https://developer.garmin.com/gc-developer-program/activity-api/
- python-garminconnect: https://github.com/cyberjunky/python-garminconnect
- Polar AccessLink: https://www.polar.com/accesslink-api/
- Suunto API Zone: https://apizone.suunto.com/
- Apple HealthKit: https://developer.apple.com/documentation/healthkit
