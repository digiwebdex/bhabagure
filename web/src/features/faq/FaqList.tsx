'use client';

import { useState } from 'react';

type Faq = { q: string; a: string };

/** Accordion; the first answer starts open, as in the design. */
export function FaqList({ items }: { items: Faq[] }) {
  const [open, setOpen] = useState<number>(0);

  return (
    <div className="flex flex-col gap-2.5">
      {items.map((item, i) => {
        const expanded = open === i;
        return (
          <div key={item.q} data-reveal className="overflow-hidden rounded-14 border border-hairline bg-white">
            <h3>
              <button
                type="button"
                id={`faq-q-${i}`}
                aria-expanded={expanded}
                aria-controls={`faq-a-${i}`}
                onClick={() => setOpen((current) => (current === i ? -1 : i))}
                className="flex w-full cursor-pointer items-center justify-between gap-3.5 px-4.5 py-4 text-left text-16 font-semibold text-ink-deep"
              >
                <span className="min-w-0">{item.q}</span>
                <span
                  aria-hidden
                  className={`flex size-6.5 shrink-0 items-center justify-center rounded-8 text-16 transition-colors duration-200 ${
                    expanded ? 'bg-blue text-white' : 'bg-paper-alt text-ink-deep'
                  }`}
                >
                  <span className={`block transition-transform duration-200 ${expanded ? 'rotate-45' : ''}`}>+</span>
                </span>
              </button>
            </h3>
            <div id={`faq-a-${i}`} role="region" aria-labelledby={`faq-q-${i}`} hidden={!expanded} className="px-4.5 pb-4.5 text-15 leading-1.6 text-muted">
              {item.a}
            </div>
          </div>
        );
      })}
    </div>
  );
}
