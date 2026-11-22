// Same-origin API client (ADR 0003): session cookie, CSRF header on state-changing requests, Problem Details errors.

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message)
  }
}

export const CSRF_HEADER = 'X-Tabularius-Request'

export async function api<T>(method: 'GET' | 'POST' | 'DELETE', path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') headers[CSRF_HEADER] = '1'
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  let response: Response
  try {
    response = await fetch(`/api/v1${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'network', 'Brak połączenia z serwerem. Sprawdź połączenie i spróbuj ponownie.')
  }
  if (response.status === 204) return undefined as T
  let data: unknown = null
  try {
    data = await response.json()
  } catch {
    data = null
  }
  if (!response.ok) {
    const problem = (data ?? {}) as { code?: unknown; title?: unknown }
    const code = typeof problem.code === 'string' ? problem.code : 'http_error'
    throw new ApiError(response.status, code, typeof problem.title === 'string' ? problem.title : `HTTP ${response.status}`)
  }
  return data as T
}

/** User-facing Polish message for known API error codes. */
export function errorMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Wystąpił nieoczekiwany błąd.'
  const messages: Record<string, string> = {
    network: e.message,
    invalid_credentials: 'Nieprawidłowy e-mail lub hasło.',
    too_many_attempts: 'Zbyt wiele prób. Spróbuj ponownie później.',
    unauthenticated: 'Sesja wygasła. Zaloguj się ponownie.',
    forbidden: 'Nie masz dostępu do tej funkcji.',
    csrf: 'Żądanie odrzucone ze względów bezpieczeństwa. Odśwież stronę.',
    invalid_name: 'Podaj imię i nazwisko.',
    invalid_email: 'Podaj prawidłowy służbowy adres e-mail.',
    unknown_sales_rep: 'Wybierz handlowca z systemu danych.',
    email_taken: 'Konto z tym adresem już istnieje.',
    sales_rep_taken: 'Ten handlowiec ma już konto.',
    shared_account: 'Wspólnego konta demonstracyjnego nie można zmieniać.',
    own_account: 'Nie możesz zmienić własnego konta.',
    admin_account: 'Konta administratora nie można zmienić z tego widoku.',
    not_found: 'Nie znaleziono.',
    invalid_current_password: 'Obecne hasło jest nieprawidłowe.',
    weak_password: 'Nowe hasło musi mieć od 12 do 128 znaków.',
    invalid_owner: 'Wybierz aktywne konto pracownika (handlowca).',
    unknown_operation: 'Wybierz operację z katalogu.',
    unexpected_parameter: 'Plan zawiera parametr spoza schematu operacji.',
    missing_period: 'Wybierz okres raportu.',
    invalid_period: 'Okres jest nieprawidłowy.',
    start_after_end: 'Początek okresu jest po jego końcu.',
    period_includes_today: 'Okres obejmuje dzień bieżący lub przyszły — wybierz dni zakończone.',
    no_completed_period: 'W tym okresie nie ma jeszcze zakończonych dni.',
    period_too_long: 'Okres może mieć najwyżej 366 dni.',
    period_too_early: 'Dane są dostępne od 1 stycznia 2020.',
    invalid_customers: 'Wybierz co najmniej jednego klienta.',
    missing_customer: 'Wybierz klienta.',
    invalid_customer: 'Wybierz klienta z listy.',
    out_of_scope: 'Klient spoza zakresu wybranego pracownika.',
    missing_manufacturer: 'Wybierz producenta.',
    invalid_manufacturer: 'Wybierz producenta z listy.',
    unknown_manufacturer: 'Nieznany producent.',
    unknown_category: 'Nieznana kategoria.',
    unknown_policy: 'Nie ma takiej wersji polityki rabatowej.',
    data_source_unavailable: 'System danych jest chwilowo niedostępny. Spróbuj ponownie za chwilę.',
    invalid_status: 'Zgłoszenie zmieniło status. Odśwież widok.',
    file_expired: 'Plik wygasł i został usunięty.',
    scope_changed: 'Raport obejmuje klientów, którzy nie są już w Twoim zakresie.',
    scope_unavailable: 'Nie można teraz sprawdzić uprawnień do raportu. Spróbuj ponownie za chwilę.',
  }
  return messages[e.code] ?? 'Nie udało się wykonać operacji. Spróbuj ponownie.'
}
