import { ApiError, errorMessage } from './http'
import { ref, shallowRef } from 'vue'

/** Loading / error / data state for one async call; the view decides how to show each state. */
export function useAsync<T>(fn: () => Promise<T>) {
  const data = shallowRef<T | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)
  async function run() {
    loading.value = true
    error.value = null
    try {
      data.value = await fn()
    } catch (e) {
      error.value = e instanceof ApiError ? errorMessage(e) : e instanceof Error ? e.message : 'Wystąpił nieoczekiwany błąd.'
    } finally {
      loading.value = false
    }
  }
  void run()
  return { data, error, loading, reload: run }
}
