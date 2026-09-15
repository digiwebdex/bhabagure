/**
 * The country's two-letter code as a small badge. Not an emoji flag: Windows browsers draw flag emoji as the bare letters,
 * so a badge looks the same everywhere. Decorative — the country's name is always beside it.
 */
export function CountryCode({ code }: { code: string }) {
  return (
    <span aria-hidden className="inline-flex h-6 min-w-8 items-center justify-center rounded-6 border border-hairline bg-paper-alt px-1.5 font-display text-11 font-bold tracking-caps-print text-ink">
      {code}
    </span>
  );
}
