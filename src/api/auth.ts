import client from './client'

export interface LoginResponse {
  sessionToken: string
  username: string
}

export const login = async (
  username: string,
  password: string,
): Promise<LoginResponse> => {
  const response = await client.post<LoginResponse>('/auth/login', {
    username,
    password,
  })
  return response.data
}


