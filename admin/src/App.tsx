import { MutationCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router'

import { AuthProvider } from './app/auth'
import { NAV_COUNTS_KEY } from './app/navCounts'
import { router } from './app/router'
import { ToastProvider } from './components/ui/feedback'
import { ApiError } from './lib/api/client'

const queryClient: QueryClient = new QueryClient({
  // Any change can move a sidebar badge (claim, confirm, cancel, mark quoted…): recount after every successful mutation.
  mutationCache: new MutationCache({ onSuccess: () => void queryClient.invalidateQueries({ queryKey: NAV_COUNTS_KEY }) }),
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      // Client errors (403, 404, 422) won't fix themselves on retry.
      retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
    },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <AuthProvider>
          <RouterProvider router={router} />
        </AuthProvider>
      </ToastProvider>
    </QueryClientProvider>
  )
}
