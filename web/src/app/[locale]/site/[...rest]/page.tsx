import { notFound } from 'next/navigation';

/** Any website address no page answers: the branded 404 (./../not-found.tsx), with a 404 status. */
export default function UnknownSitePage() {
  notFound();
}
