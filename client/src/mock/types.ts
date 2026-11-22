// PROTOTYPE: shapes of the remaining mocked flows (confirmation, mail correspondence). Real contract: lib/types.ts.

import type { Actor, Operation, PlanVersion, TicketStatus } from '../lib/types'

export type { Actor, Operation, PlanVersion, TicketStatus }

export type MessageKind = 'request' | 'reply' | 'question' | 'notification' | 'refusal' | 'confirmation_request'

export interface Message {
  id: string
  direction: 'in' | 'out'
  at: string
  kind: MessageKind
  /** Incoming body as plain text. Shown only in production mode (never in public demo views). */
  body: string
  /** Outgoing messages: template key and its rendered text (backend templates, never model free text). */
  template?: string
  delivery?: 'accepted' | 'unknown' | 'failed' | 'pending'
}

export interface TicketEvent {
  at: string
  actor: Actor
  actorName: string
  text: string
}

export interface Ticket {
  id: string
  ownerId: string
  /** Production: employee name and registered address. Demo: synthetic alias only. */
  employeeName: string
  employeeEmail: string
  participantAlias: string
  receivedAt: string
  status: TicketStatus
  reason?: string
  plans: PlanVersion[]
  messages: Message[]
  events: TicketEvent[]
  fileId?: string
  clarificationRounds: number
  /** Customers in the owner's scope offered for an ambiguous reference. */
  candidates?: string[]
}

export interface ReportFile {
  id: string
  ticketId: string
  ownerId: string
  name: string
  operation: Operation
  periodLabel: string
  scopeLabel: string
  createdAt: string
  expiresAt: string
  /** CSV content rows (first row = header). Money as integer grosze converted to text by the generator. */
  header: string[]
  rows: { type: 'meta' | 'detail' | 'total'; cells: string[] }[]
}

export interface Account {
  id: string
  name: string
  email: string
  repCode: string
  customers: number
  status: 'active' | 'blocked'
  lastLogin: string | null
  shared?: boolean
}

export interface AuditEntry {
  at: string
  actor: string
  action: string
  target: string
}
