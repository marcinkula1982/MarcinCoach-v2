# Przeniesienie wszystkich fetch() na client (axios)

## Cel
Zastąpienie wszystkich wywołań `fetch(...)` do backendu na użycie `client` (axios), aby wszystkie requesty korzystały z interceptora odpowiedzi i automatycznie obsługiwały wygasłe sesje (401).

## Znalezione miejsca z fetch()

1. **Health check** (`src/App.tsx`, linia ~170):
   - `fetch(`${API_BASE_URL}/health`, { credentials: 'include' })`
   - Publiczny endpoint, bez autoryzacji

2. **Update workout meta** (`src/App.tsx`, linia ~378):
   - `fetch(`${API_BASE_URL}/workouts/${currentWorkoutId}/meta`, { method: 'PATCH', ... })`
   - Wymaga autoryzacji (nagłówki `x-session-token`, `x-user-id`)

3. **Upload file** (`src/App.tsx`, linia ~411):
   - `fetch(`${API_BASE_URL}/workouts/upload`, { method: 'POST', ... })`
   - Wymaga autoryzacji
   - **Uwaga**: Istnieje już funkcja `uploadTcxFile()` w `src/api/workouts.ts`!

4. **Update workout meta after upload** (`src/App.tsx`, linia ~436):
   - `fetch(`${API_BASE_URL}/workouts/${workoutId}/meta`, { method: 'PATCH', ... })`
   - Wymaga autoryzacji

## Zmiany w plikach

### 1. Aktualizacja `src/api/workouts.ts`
- **Dodanie funkcji `updateWorkoutMeta()`**:
  ```typescript
  export async function updateWorkoutMeta(id: number, workoutMeta: WorkoutMeta): Promise<void> {
    await client.patch(`/workouts/${id}/meta`, { workoutMeta }, {
      headers: buildAuthHeaders(),
    })
  }
  ```

### 2. Aktualizacja `src/App.tsx`

#### 2.1. Importy
- **Dodanie importu** `uploadTcxFile` i `updateWorkoutMeta` z `./api/workouts`
- **Dodanie importu** `client` z `./api/client` (jeśli jeszcze nie ma)

#### 2.2. Health check (linia ~170)
- **Zastąpienie** `fetch()` na `client.get('/health')`:
  ```typescript
  useEffect(() => {
    client.get('/health')
      .then((res) => {
        setBackendHealth(`${res.status} ${JSON.stringify(res.data)}`)
      })
      .catch((e) => setBackendHealth(`ERR ${String(e)}`))
  }, [])
  ```

#### 2.3. Update workout meta (linia ~378)
- **Zastąpienie** `fetch()` na `updateWorkoutMeta()`:
  ```typescript
  if (currentWorkoutId) {
    try {
      await updateWorkoutMeta(currentWorkoutId, workoutMeta)
      const fresh = await getWorkouts()
      setWorkouts(fresh)
      setNote('')
      setSaveSuccess('WorkoutMeta zaktualizowane')
      return
    } catch (error: any) {
      const msg = error?.response?.data?.message || error?.message || String(error)
      setSaveError(`Backend błąd: ${msg}`)
      return
    }
  }
  ```

#### 2.4. Upload file (linia ~411)
- **Zastąpienie** `fetch()` na `uploadTcxFile()`:
  ```typescript
  try {
    const uploadedWorkout = await uploadTcxFile(currentFile)
    const workoutId = uploadedWorkout.id

    // Update workout meta
    await updateWorkoutMeta(workoutId, workoutMeta)
    
    // ... reszta logiki sukcesu
  } catch (error: any) {
    if (error?.response?.status === 409) {
      setSaveSuccess('Ten trening już jest w bazie (duplikat).')
      setSaveError(null)
      await loadWorkouts()
      return
    }
    const msg = error?.response?.data?.message || error?.message || String(error)
    setSaveError(`Backend błąd: ${msg}`)
  }
  ```

#### 2.5. Update workout meta after upload (linia ~436)
- **Usunięcie** - już obsłużone w powyższym bloku przez `updateWorkoutMeta()`

## Szczegóły implementacji

### Obsługa błędów
- Wszystkie requesty przez `client` automatycznie korzystają z interceptora odpowiedzi
- Interceptor obsługuje `401 SESSION_EXPIRED` / `INVALID_SESSION` automatycznie
- Inne błędy są obsługiwane przez `catch` bloki z odpowiednimi komunikatami

### Uwagi techniczne
- `uploadTcxFile()` już istnieje w `workouts.ts` - należy użyć istniejącej funkcji
- `buildAuthHeaders()` jest już używane w `workouts.ts` - można użyć tego samego podejścia
- Health check nie wymaga autoryzacji, ale może używać `client` dla spójności
- Wszystkie requesty przez `client` automatycznie mają `withCredentials: true`

## Weryfikacja
- Wszystkie `fetch()` zostały zastąpione na `client` lub funkcje pomocnicze
- Health check używa `client.get('/health')`
- Upload używa `uploadTcxFile()` z `workouts.ts`
- Update meta używa `updateWorkoutMeta()` (nowa funkcja w `workouts.ts`)
- Wszystkie requesty korzystają z interceptora odpowiedzi
- Obsługa błędów jest spójna (try/catch z odpowiednimi komunikatami)

