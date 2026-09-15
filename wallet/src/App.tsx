import { QueryClient, QueryClientProvider, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

import { SIGNED_OUT_EVENT } from './lib/api'
import { useMe } from './lib/queries'
import { SignIn } from './SignIn'
import { WalletScreen } from './WalletScreen'
import { Pending, Shell } from './ui'

/** One screen behind one sign-in (docs/phase-7-hr-attendance-bonus-wallet.md §8). */
export default function App() {
  const [client] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } } }))

  return (
    <QueryClientProvider client={client}>
      <Gate />
    </QueryClientProvider>
  )
}

function Gate() {
  const client = useQueryClient()
  const me = useMe()

  // A session that ends (idle, signed out elsewhere) returns to sign-in and forgets every figure on the page.
  useEffect(() => {
    const onSignedOut = () => client.resetQueries()
    window.addEventListener(SIGNED_OUT_EVENT, onSignedOut)
    return () => window.removeEventListener(SIGNED_OUT_EVENT, onSignedOut)
  }, [client])

  if (me.isPending)
    return (
      <Shell>
        <Pending />
      </Shell>
    )
  if (me.isError || !me.data) return <SignIn onSignedIn={() => void client.invalidateQueries({ queryKey: ['me'] })} />

  return <WalletScreen me={me.data} onSignedOut={() => client.resetQueries()} />
}
