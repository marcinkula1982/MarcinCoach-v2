// src/api/client.ts
import axios from 'axios'

axios.defaults.baseURL = 'http://localhost:3000'
axios.defaults.headers.common['x-session-token'] =
  'fccfeb9c-e00c-47eb-bd11-5bcd4798c2d8'
axios.defaults.headers.common['x-username'] = 'marcin'

const client = axios

export { client }
export default client
