# Integracje danych treningowych — wnioski

Data: 2026-04-26

## Obsługiwane źródła danych

| Źródło | Typ integracji | Biblioteka / API | Trudność | Status |
|---|---|---|---|---|
| Strava | Oficjalne OAuth2 API | REST API | Łatwa | ✅ Na start |
| Garmin | Nieoficjalne (scraping Garmin Connect) | `python-garminconnect` | Średnia | ✅ Na start |
| Polar | Oficjalne OAuth2 API | `polar-accesslink` (PyPI) | Łatwa | 🔜 Jeśli uda się |
| Suunto | Oficjalne OAuth2 API | Suunto Cloud API (Azure) | Średnia (formalności partnerskie) | 🔜 Jeśli uda się |
| Apple Watch | Brak chmurowego API | — | Niemożliwe z backendu | ❌ Nie bezpośrednio |
| Import plików | TCX / FIT (min. 6, opt. 10 treningów) | — | — | ✅ Fallback dla wszystkich |

## Uwagi

- **Garmin**: `python-garminconnect` działa przez symulację logowania do Garmin Connect (nie oficjalne API). Aktywnie utrzymywane. Ryzyko: może się psuć po zmianach auth po stronie Garmin. MFA może sprawiać problemy. Akceptujemy ryzyko — naprawiamy jak się posypie.
- **Strava**: Oficjalne OAuth2, stabilne, bez ryzyka. Priorytet #1.
- **Polar**: Oficjalne AccessLink API z gotowym przykładem w Pythonie. Najłatwiejsze z dodatkowych.
- **Suunto**: Oficjalne Cloud API (Azure API Management), wymaga rejestracji w programie partnerskim. Daje FIT, HR, GPS.
- **Apple Watch**: Apple Health celowo nie ma chmurowego API (decyzja prywatności). Użytkownicy Apple Watch trafiają do ścieżki importu plików (FIT/TCX przez apki trzecie np. Health Auto Export).

## Ekran onboardingu — źródła danych

```
[ Garmin ]  [ Strava ]  [ Polar ]  [ Suunto ]
[ Wgraj pliki FIT/TCX ]
```

Minimalna wymagana liczba treningów do analizy: **6**
Optymalna liczba treningów: **10+**

## Linki

- python-garminconnect: https://github.com/cyberjunky/python-garminconnect
- Polar AccessLink przykład: https://github.com/polarofficial/accesslink-example-python
- polar-accesslink PyPI: https://pypi.org/project/polar-accesslink/
- Suunto Cloud API: https://apizone.suunto.com/
- Strava API docs: https://developers.strava.com/
