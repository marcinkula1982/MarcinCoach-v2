# MarcinCoach v2 - roadmap

Status dokumentu: aktywny plan dalszych prac.

Po zrealizowaniu funkcjonalnosci nie dopisuj jej tutaj jako historii. Przenies jej status do `docs/status.md`, do sekcji `Dziennik zrealizowanych funkcjonalnosci`, a w tym pliku zostaw tylko kolejne prace do wykonania.

## Kierunek MVP

MVP to webowy coach/planner, nie tracker GPS.

Glowna petla:
1. plan rolling 14 dni,
2. trening,
3. deterministyczny feedback bez AI,
4. korekta kolejnych dni.

Nie robimy w MVP:
- platnosci,
- social feedu,
- LiveTrack,
- lokalnej bazy biegow,
- smogu/pogody,
- HRV/snu/readiness.

## Najblizsza kolejnosc

1. Feedback po treningu w UX:
   - po zapisanym/imporcie treningu pokazac `POST /api/workouts/{id}/feedback/generate`,
   - dodac widok ponownego odczytu `GET /api/workouts/{id}/feedback`,
   - pokazac sekcje: praise, deviations, conclusions, planImpact,
   - nie udawac AI: komunikaty sa deterministyczne i oparte o fakty/training compliance.

2. Cross-training hardening:
   - dodac UI korekty klasyfikacji aktywnosci zaimportowanych jako `other`,
   - dopracowac planowane aktywnosci wielodniowe i szybkie powtarzanie tygodniowe,
   - dodac fixture providerowe dla strength/bike/swim,
   - zachowac `GET /api/weekly-plan` jako kompatybilny fallback.

3. Deeper data hardening:
   - dodac test na realnym pliku `.fit`,
   - zdecydowac, czy raw FIT/GPX zapisywac w osobnej tabeli,
   - dopracowac cleaning rules dla cadence, power, elevation,
   - wykorzystac `profile.paceZones` w planie i feedbacku.

4. Races w profilu:
   - upewnic sie, ze frontend pozwala dodac start recznie: nazwa, data, dystans, priorytet A/B/C, cel czasowy,
   - pokazac `primaryRace` i wplyw na taper/peak/base,
   - race import nie blokuje MVP, bo fallbackiem zostaje reczne wpisanie.

5. Garmin Event Dashboard spike:
   - sprawdzic `https://connect.garmin.com/app/event-dashboard`,
   - sprawdzic odczyt "Moje wydarzenia",
   - sprawdzic wyszukiwanie eventow po nazwie/lokalizacji/dacie,
   - sprawdzic import wybranego eventu jako race A/B/C,
   - zakonczyc spike jasnym statusem: stabilne / kruche / niedostepne.

## Later

- Smog/pogoda z lokalna precyzja lepsza niz samo miasto.
- ZmierzymyCzas jako docelowe partnerstwo lub selektywne zrodlo eventow.
- HRV/sen/readiness z urzadzen.
- Platnosci/BLIK po walidacji MVP.
- Polar/Suunto jako kolejne integracje po domknieciu MVP.
- Produkcyjny AI provider hardening: limity, cache, observability.

## Aktywne referencje

- `docs/status.md` - wykonane funkcjonalnosci, technologie, walidacje.
- `docs/integrations.md` - integracje Garmin/Strava/Polar/Suunto.
- `docs/deploy/frontend-iqhost-deploy.txt` - deploy frontu.
- `CLAUDE.md` / `AGENTS.md` - zasady pracy AI i deployu.
