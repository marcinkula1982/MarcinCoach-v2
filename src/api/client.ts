// src/api/client.ts
import axios from 'axios'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim() || '/api'
axios.defaults.baseURL = apiBaseUrl

const sessionToken = localStorage.getItem('tcx-session-token')
const username = localStorage.getItem('tcx-username')
if (sessionToken) {
  axios.defaults.headers.common['x-session-token'] = sessionToken
}
if (username) {
  axios.defaults.headers.common['x-username'] = username
}

axios.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status as number | undefined
    const message = error?.response?.data?.message as string | undefined
    const hasSession = Boolean(localStorage.getItem('tcx-session-token'))
    const shouldForceLogout =
      status === 401 ||
      status === 403 ||
      message === 'INVALID_SESSION' ||
      message === 'SESSION_EXPIRED' ||
      message === 'MISSING_SESSION_HEADERS' ||
      message === 'SESSION_USER_MISMATCH'

    if (shouldForceLogout && hasSession) {
      localStorage.removeItem('tcx-session-token')
      localStorage.removeItem('tcx-username')
      delete axios.defaults.headers.common['x-session-token']
      delete axios.defaults.headers.common['x-username']
      window.dispatchEvent(new CustomEvent('tcx-auth-logout'))
    }

    return Promise.reject(error)
  },
)

const client = axios

export { client }
export default client
