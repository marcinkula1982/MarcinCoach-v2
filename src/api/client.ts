// src/api/client.ts
import axios from 'axios'

axios.defaults.baseURL = 'http://localhost:8000/api'

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
    const hasSession = Boolean(localStorage.getItem('tcx-session-token'))

    if (status === 401 && hasSession) {
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
