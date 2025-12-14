# Dodanie interceptora odpowiedzi dla wygasłych sesji

## Cel
Dodanie interceptora odpowiedzi w axios, który automatycznie obsługuje wygasłe sesje: czyści localStorage i przeładowuje stronę, aby użytkownik mógł się ponownie zalogować.

## Założenia MVP
- Gdy backend zwraca `401` z komunikatem `SESSION_EXPIRED` lub `INVALID_SESSION`
- Automatyczne czyszczenie localStorage (`tcx-session-token`, `tcx-username`)
- Automatyczne przeładowanie strony (`window.location.reload()`)
- Minimalna implementacja bez dodatkowych komunikatów (na razie)

## Zmiany w plikach

### 1. Aktualizacja `src/api/client.ts`
- **Dodanie interceptora odpowiedzi** przed `export default client`:
  ```typescript
  client.interceptors.response.use(
    (res) => res,
    (err) => {
      const status = err?.response?.status
      const msg = err?.response?.data?.message

      if (status === 401 && (msg === 'SESSION_EXPIRED' || msg === 'INVALID_SESSION')) {
        localStorage.removeItem('tcx-session-token')
        localStorage.removeItem('tcx-username')
        // szybkie i brutalne, ale skuteczne na MVP:
        window.location.reload()
      }

      return Promise.reject(err)
    },
  )
  ```

## Szczegóły implementacji

### Logika interceptora
- **Success handler**: Zwraca odpowiedź bez zmian `(res) => res`
- **Error handler**: 
  - Sprawdza status odpowiedzi (`err?.response?.status`)
  - Sprawdza komunikat błędu (`err?.response?.data?.message`)
  - Jeśli `status === 401` i komunikat to `SESSION_EXPIRED` lub `INVALID_SESSION`:
    1. Usuwa `tcx-session-token` z localStorage
    2. Usuwa `tcx-username` z localStorage
    3. Przeładowuje stronę (`window.location.reload()`)
  - W przeciwnym razie: zwraca odrzuconą Promise (`Promise.reject(err)`)

### Uwagi techniczne
- Interceptor działa globalnie dla wszystkich requestów przez `client`
- `window.location.reload()` jest "brutalne", ale skuteczne na MVP - czyści cały stan aplikacji
- W przyszłości można zastąpić `reload()` bardziej eleganckim rozwiązaniem (np. przekierowanie do strony logowania, wyświetlenie komunikatu)
- Interceptor nie blokuje innych błędów - tylko obsługuje wygasłe sesje

## Weryfikacja
- Interceptor został dodany do `client` w `src/api/client.ts`
- Interceptor sprawdza status `401` i komunikaty `SESSION_EXPIRED` / `INVALID_SESSION`
- Interceptor czyści localStorage (`tcx-session-token`, `tcx-username`)
- Interceptor przeładowuje stronę przy wygasłych sesjach
- Inne błędy są przekazywane dalej (`Promise.reject(err)`)

