# TrainingSignals v0 (MVP)

`schemaVersion`: "0.1"

## window
- Zakres czasowy, którego dotyczą sygnały treningowe.
- Definiuje granice analizy, w oparciu o które liczone są wszystkie pozostałe pola.

## load
- Zbiorcze informacje o objętości treningu w zadanym oknie czasowym.
- Zawiera m.in. liczbę jednostek treningowych oraz sumaryczny czas i dystans dla okna.

## intensity
- Rozkład czasu spędzonego w poszczególnych strefach intensywności (Z1–Z5) w ramach danego okna.
- Reprezentuje, ile czasu użytkownik spędza w każdej strefie, bez interpretacji jakości treningu.

## recoverySignals
- Informacje o wzorcach odpoczynku i obciążenia w ujęciu dzień‑po‑dniu w zadanym oknie.
- Obejmuje sygnały związane ze zmęczeniem, regeneracją i zgłaszanymi objawami, zapisane jako neutralne flagi/oznaczenia.

## constraints
- Stałe lub pół‑trwałe ograniczenia i preferencje użytkownika, wpływające na planowanie.
- Przechowywane jako dane opisujące warunki brzegowe, bez wnioskowania czy zaleceń.

## planningInputs
- Bazowe, liczbowe parametry wejściowe do algorytmów planowania.
- Dane opisowe, bez zakodowanych reguł treningowych ani interpretacji (służą wyłącznie jako wejście do dalszych warstw planowania).


