export type AiRisk = 'fatigue' | 'inconsistency' | 'low-compliance' | 'none'

export type AiInsights = {
  generatedAtIso: string
  windowDays: number
  summary: string[]
  risks: AiRisk[]
  questions: string[]
  confidence: number
}


