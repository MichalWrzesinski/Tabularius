// Contract of the Tabularius API (api/src/Module/Ticket/Http/TicketView.php, File/Http/FileController.php).

export type TicketStatus =
  | 'received'
  | 'interpreting'
  | 'needs_clarification'
  | 'awaiting_confirmation'
  | 'queued'
  | 'running'
  | 'completed'
  | 'rejected'
  | 'needs_intervention'

export type Operation = 'sales_report' | 'parts_catalog' | 'customer_discount_simulation'

export type Actor = 'system' | 'model' | 'employee' | 'admin'

export interface PlanVersion {
  version: number
  author: Actor
  at: string
  operation: Operation | null
  /** Resolved parameters with Polish labels (concrete dates). */
  params: { label: string; value: string }[]
  issues: string[]
}

export interface TicketEvent {
  at: string
  actor: Actor
  actorName: string
  text: string
}

export interface TicketSummary {
  id: string
  /** Display number, e.g. „T-1001”. */
  number: string
  ownerId: string
  employeeName: string
  employeeEmail: string
  /** Demo mail tickets: the synthetic alias shown instead of any person (ID-DEMO-3). */
  participantAlias: string | null
  source: 'manual' | 'mail'
  receivedAt: string
  updatedAt: string
  status: TicketStatus
  /** Stable reason code (REASON_LABEL), never free text. */
  reason: string | null
  operation: Operation | null
  operationLabel: string | null
  planVersion: number
  fileId: string | null
  /** Summaries carry the current plan only; details every version. */
  plans: PlanVersion[]
}

export interface Ticket extends TicketSummary {
  referenceDate: string
  events: TicketEvent[]
  messages: never[]
  clarificationRounds: number
}

/** The representative's view of an own ticket (confirmation screen): no message contents, no history. */
export type MyTicket = Pick<
  TicketSummary,
  'id' | 'number' | 'receivedAt' | 'status' | 'reason' | 'operation' | 'operationLabel' | 'fileId' | 'plans'
>

export interface FileMeta {
  id: string
  ticketId: string
  ticketNumber: string
  ownerId: string
  ownerName: string
  name: string
  operation: Operation
  periodLabel: string
  scopeLabel: string
  rowCount: number
  empty: boolean
  sizeBytes: number
  createdAt: string
  expiresAt: string
}

export interface ReportPreview extends FileMeta {
  header: string[]
  rows: { type: 'meta' | 'detail' | 'total'; cells: string[] }[]
  previewTruncated: boolean
}

export interface Catalog {
  profile: string
  operations: { id: Operation; label: string; parameters: Record<string, boolean> }[]
  manufacturers: { code: string; name: string }[]
  categories: string[]
}

export interface CustomerRef {
  code: string
  name: string
  active: boolean
}
