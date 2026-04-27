import client from './client'

export type UserProfile = {
  id: number
  userId: number
  preferredRunDays: string | null
  preferredSurface: string | null
  goals: string | null
  constraints: string | null
  races?: unknown[] | null
  availability?: Record<string, unknown> | null
  health?: Record<string, unknown> | null
  equipment?: Record<string, unknown> | null
  hrZones?: Record<string, unknown> | null
  crossTrainingPromptPreference?: 'ask_before_plan' | 'do_not_ask'
  primaryRace?: Record<string, unknown> | null
  quality?: Record<string, unknown> | null
  onboardingCompleted?: boolean
  createdAt: string
  updatedAt: string
}

export type UpdateProfilePayload = {
  preferredRunDays?: string
  preferredSurface?: string
  goals?: string
  constraints?: string
  races?: Array<Record<string, unknown>>
  availability?: Record<string, unknown>
  health?: Record<string, unknown>
  equipment?: Record<string, unknown>
  hrZones?: Record<string, unknown>
  crossTrainingPromptPreference?: 'ask_before_plan' | 'do_not_ask'
}

export async function getMyProfile(): Promise<UserProfile> {
  const res = await client.get<UserProfile>('/me/profile')
  return res.data
}

export async function updateMyProfile(
  payload: UpdateProfilePayload,
): Promise<UserProfile> {
  const res = await client.put<UserProfile>('/me/profile', payload)
  return res.data
}


