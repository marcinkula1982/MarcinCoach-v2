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

const client = axios

export { client }
export default client
