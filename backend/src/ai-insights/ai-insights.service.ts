import {
  Injectable,
  InternalServerErrorException,
} from '@nestjs/common'
import type { UserProfileConstraints } from '../training-context/training-context.types'
import { TrainingFeedbackService } from '../training-feedback/training-feedback.service'
import { UserProfileService } from '../user-profile/user-profile.service'
import type { PlanFeedbackSignals } from '../training-feedback/training-feedback.types'
import type { AiInsights, AiRisk } from './ai-insights.types'
import { aiInsightsSchema } from './ai-insights.schema'
import { AiCacheService } from '../ai-cache/ai-cache.service'

const stableStringify = require('fast-json-stable-stringify')
const OpenAI = require('openai')

type AiInputPayload = {
  user: {
    username: string
    hrZones?: UserProfileConstraints['hrZones']
  }
  feedback: PlanFeedbackSignals
}

@Injectable()
export class AiInsightsService {
  constructor(
    private readonly trainingFeedbackService: TrainingFeedbackService,
    private readonly userProfileService: UserProfileService,
    private readonly aiCacheService: AiCacheService,
  ) {}

  private getProvider(): 'stub' | 'openai' {
    const raw = (process.env.AI_INSIGHTS_PROVIDER || 'stub').toLowerCase()
    return raw === 'openai' ? 'openai' : 'stub'
  }

  private stripMarkdownFences(raw: string): string {
    const trimmed = raw.trim()
    if (!trimmed.startsWith('```')) return trimmed
    return trimmed.replace(/^```[a-zA-Z]*\n?/, '').replace(/```$/, '').trim()
  }

  private extractResponseOutputText(response: any): string | undefined {
    const direct = response?.output_text
    if (typeof direct === 'string' && direct.trim().length > 0) return direct

    const out = response?.output
    if (!Array.isArray(out)) return undefined

    for (const item of out) {
      const content = item?.content
      if (!Array.isArray(content)) continue

      for (const c of content) {
        if (c?.type === 'output_text' || c?.type === 'text') {
          if (typeof c?.text === 'string' && c.text.trim().length > 0) return c.text
          if (typeof c?.text?.value === 'string' && c.text.value.trim().length > 0) return c.text.value
        }

        // Fallback: even without type, accept plain string text
        if (typeof c?.text === 'string' && c.text.trim().length > 0) return c.text
      }
    }

    return undefined
  }

  private orderedRisks(risks: Set<AiRisk>): AiRisk[] {
    const order: AiRisk[] = ['fatigue', 'inconsistency', 'low-compliance']
    const out = order.filter((r) => risks.has(r))
    if (out.length === 0) return ['none']
    return out
  }

  private buildStubInsights(payload: AiInputPayload): AiInsights {
    const feedback = payload.feedback

    if (feedback.counts.totalSessions === 0) {
      return {
        generatedAtIso: feedback.generatedAtIso,
        windowDays: feedback.windowDays,
        summary: [
          `Brak danych w ostatnich ${feedback.windowDays} dniach (brak treningów w oknie).`,
        ],
        risks: ['none'],
        questions: ['Czy zapisy treningów są kompletne w aplikacji?'],
        confidence: 0.2,
      }
    }

    const risks = new Set<AiRisk>()
    if (feedback.complianceRate.unplannedPct >= 50) risks.add('low-compliance')
    if (feedback.fatigue.trueCount >= 2) risks.add('fatigue')
    if (feedback.counts.totalSessions < feedback.windowDays / 7) risks.add('inconsistency')

    const ordered = this.orderedRisks(risks)

    const summary: string[] = []
    summary.push(`W oknie ${feedback.windowDays} dni: ${feedback.counts.totalSessions} sesji.`)
    if (ordered.includes('low-compliance')) {
      summary.push(`Wysoki odsetek treningów spontanicznych: ${feedback.complianceRate.unplannedPct}%.`)
    }
    if (ordered.includes('fatigue')) {
      summary.push(`Częste flagi zmęczenia: ${feedback.fatigue.trueCount}.`)
    }
    if (ordered.includes('inconsistency')) {
      summary.push(`Niska regularność: ${feedback.counts.totalSessions} sesji w ${feedback.windowDays} dni.`)
    }
    if (ordered.length === 1 && ordered[0] === 'none') {
      summary.push('Brak wyraźnych ryzyk w danych za okno.')
    }

    const questions: string[] = []
    if (ordered.includes('fatigue')) questions.push('Czy odczuwasz zmęczenie lub gorszą regenerację w ostatnim tygodniu?')
    if (ordered.includes('low-compliance')) questions.push('Czy plan powinien lepiej pasować do Twojej dostępności?')
    if (ordered.includes('inconsistency')) questions.push('Ile dni w tygodniu realnie chcesz biegać?')
    if (questions.length === 0) questions.push('Czy wszystko jest OK z aktualnym obciążeniem?')

    const confidence =
      feedback.counts.totalSessions === 0 ? 0.2 : feedback.counts.totalSessions < 3 ? 0.4 : 0.6

    return {
      generatedAtIso: feedback.generatedAtIso,
      windowDays: feedback.windowDays,
      summary: summary.slice(0, 5),
      risks: ordered,
      questions: questions.slice(0, 3),
      confidence,
    }
  }

  private async callOpenAI(payload: AiInputPayload): Promise<AiInsights> {
    const apiKey = process.env.OPENAI_API_KEY
    if (!apiKey || !apiKey.trim()) {
      throw new InternalServerErrorException('OPENAI_API_KEY missing')
    }

    const client = new OpenAI({ apiKey })
    const model = process.env.AI_INSIGHTS_MODEL ?? 'gpt-5-mini'
    const maxOut = Number(process.env.AI_INSIGHTS_MAX_OUTPUT_TOKENS ?? '1200')

    const instructions =
      'Return ONLY valid JSON (no markdown, no prose). The JSON must match this TypeScript type exactly: ' +
      '{"generatedAtIso":string,"windowDays":number,"summary":string[<=5],"risks":("fatigue"|"inconsistency"|"low-compliance"|"none")[],"questions":string[<=3],"confidence":number(0..1)}. ' +
      'generatedAtIso MUST equal feedback.generatedAtIso from the input payload.'

    const input = stableStringify(payload)

    const response: any = await client.responses.create({
      model,
      instructions,
      input,
      max_output_tokens: maxOut,
    })

    const outputText = this.extractResponseOutputText(response)

    if (typeof outputText !== 'string' || outputText.trim().length === 0) {
      throw new InternalServerErrorException('OpenAI response missing text')
    }

    const jsonText = this.stripMarkdownFences(outputText)

    let parsedJson: any
    try {
      parsedJson = JSON.parse(jsonText)
    } catch {
      throw new InternalServerErrorException('OpenAI returned non-JSON content')
    }

    const parsed = aiInsightsSchema.safeParse(parsedJson)
    if (!parsed.success) {
      throw new InternalServerErrorException(
        `AiInsights validation failed: ${JSON.stringify(parsed.error.format())}`,
      )
    }

    return parsed.data
  }

  async getInsightsForUser(
    userId: number,
    username: string,
    opts?: { days?: number },
  ): Promise<{ payload: AiInsights; cache: 'hit' | 'miss' }> {
    const days = opts?.days ?? 28

    // Check cache
    const cached = this.aiCacheService.get<AiInsights>('insights', userId, days)
    if (cached) {
      return cached
    }

    const feedback = await this.trainingFeedbackService.getFeedbackForUser(userId, { days })
    const constraints = await this.userProfileService.getConstraintsForUser(userId)

    const payload: AiInputPayload = {
      user: {
        username,
        ...(constraints.hrZones ? { hrZones: constraints.hrZones } : {}),
      },
      feedback,
    }

    const provider = this.getProvider()
    const insights =
      provider === 'openai' ? await this.callOpenAI(payload) : this.buildStubInsights(payload)

    // Hard rule: generatedAtIso must be exactly feedback.generatedAtIso
    const normalized: AiInsights = {
      ...insights,
      generatedAtIso: feedback.generatedAtIso,
      windowDays: feedback.windowDays,
    }

    const validated = aiInsightsSchema.safeParse(normalized)
    if (!validated.success) {
      throw new InternalServerErrorException(
        `AiInsights validation failed: ${JSON.stringify(validated.error.format())}`,
      )
    }

    const payload_result = validated.data

    // Store in cache
    this.aiCacheService.set('insights', userId, days, payload_result)

    return { payload: payload_result, cache: 'miss' }
  }
}


