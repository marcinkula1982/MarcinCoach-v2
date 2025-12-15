// src/api/client.ts
import axios from 'axios'

const baseURL =
  import.meta.env.VITE_API_BASE_URL?.trim() || 'http://localhost:3000'

const client = axios.create({
  baseURL,
  // używamy headerów x-user-id / x-session-token, nie cookies
  withCredentials: false,
  timeout: 15_000,
})

console.log('API BASE URL:', baseURL)

// Doklejaj sesję do każdego requestu
client.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('tcx-session-token')
    const user = localStorage.getItem('tcx-username')

    // axios ma różne typy headers; to najprostsza forma
    config.headers = config.headers ?? {}

    if (token) (config.headers as any)['x-session-token'] = token
    if (user) (config.headers as any)['x-user-id'] = user

    return config
  },
  (error) => Promise.reject(error),
)

// Obsługa wygasłej sesji (401)
client.interceptors.response.use(
  (res) => res,
  (err) => {
    const status = err?.response?.status
    const msg = err?.response?.data?.message

    const isSessionExpired =
      status === 401 &&
      (msg === 'SESSION_EXPIRED' ||
        msg === 'INVALID_SESSION' ||
        msg === 'UNAUTHORIZED')

    if (isSessionExpired) {
      localStorage.removeItem('tcx-session-token')
      localStorage.removeItem('tcx-username')

      // nie robimy window.location.reload() — dajemy sygnał aplikacji
      window.dispatchEvent(new Event('auth:expired'))
    }

    return Promise.reject(err)
  },
)

export default client
