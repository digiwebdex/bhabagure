'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { useFormatters } from '@/lib/use-formatters';
import { useSiteUi } from '@/state/site-ui';

import { answerQuestion, destinationAnswer, pricesAnswer, type AssistantContext } from './answer';

type Message = { from: 'bot' | 'me'; text: string };

/** "প্রশ্ন করুন" — bottom-left assistant with quick replies. Not AI: answers come from live content. */
export function ChatAssistant() {
  const t = useTranslations('assistant');
  const tp = useTranslations('packages');
  const tRoot = useTranslations();
  const views = useSiteContent();
  const f = useFormatters();
  const open = useSiteUi((state) => state.chatOpen);
  const toggle = useSiteUi((state) => state.toggleChat);
  const close = useSiteUi((state) => state.closeChat);

  const [messages, setMessages] = useState<Message[]>([{ from: 'bot', text: t('greeting') }]);
  const [draft, setDraft] = useState('');
  const scroller = useRef<HTMLDivElement>(null);

  const ctx: AssistantContext = useMemo(
    () => ({ views, t, tp, f, faqVisaAnswer: (tRoot.raw('faq') as { q: string; a: string }[])[1]?.a ?? t('howToBook') }),
    [views, t, tp, f, tRoot],
  );

  const firstDestination = views.destinations[0];
  const quick: { label: string; reply: () => string }[] = [
    ...(firstDestination ? [{ label: t('quickDestination', { destination: firstDestination.name }), reply: () => destinationAnswer(firstDestination.slug, ctx) }] : []),
    { label: t('quickPrices'), reply: () => pricesAnswer(ctx) },
    { label: t('quickVisa'), reply: () => ctx.faqVisaAnswer },
    { label: t('quickBooking'), reply: () => t('howToBook') },
  ];

  useEffect(() => {
    const el = scroller.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages, open]);

  const ask = (question: string, reply: () => string) => {
    setMessages((prev) => [...prev, { from: 'me', text: question }]);
    setDraft('');
    window.setTimeout(() => setMessages((prev) => [...prev, { from: 'bot', text: reply() }]), 420);
  };

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const question = draft.trim();
    if (question) ask(question, () => answerQuestion(question, ctx));
  };

  return (
    <div className="pointer-events-none fixed top-fluid-10-18 bottom-fluid-12-18 left-fluid-10-18 z-55 flex max-w-chat-panel flex-col items-start justify-end gap-3">
      {open ? (
        <div role="dialog" aria-label={t('title')} className="pointer-events-auto flex max-h-chat-max min-h-0 w-chat-panel flex-col overflow-hidden rounded-20 border border-hairline bg-white shadow-panel">
          <div className="flex shrink-0 items-center gap-3 bg-linear-135/srgb from-blue to-blue-deep px-4 py-3.5 text-white">
            <span aria-hidden className="flex size-9 shrink-0 items-center justify-center rounded-full bg-white/20 text-18">
              ◕
            </span>
            <span className="flex min-w-0 flex-1 flex-col leading-1.2">
              <strong className="text-15">{t('title')}</strong>
              <span className="font-display text-12 opacity-85">{t('subtitle')}</span>
            </span>
            <button type="button" onClick={close} aria-label={t('title')} className="size-7 shrink-0 cursor-pointer rounded-8 bg-white/18 text-15 text-white">
              ×
            </button>
          </div>
          <div ref={scroller} aria-live="polite" className="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto bg-row-alt px-4 py-3.5">
            {messages.map((m, i) => (
              <div
                key={i}
                className={`max-w-bubble rounded-14 border px-3.25 py-2.5 text-14 leading-1.5 whitespace-pre-line ${
                  m.from === 'bot' ? 'self-start border-hairline bg-white text-ink-deep' : 'self-end border-transparent bg-linear-135/srgb from-blue to-blue-deep text-white'
                }`}
              >
                {m.text}
              </div>
            ))}
          </div>
          <div className="flex shrink-0 flex-col gap-2 border-t border-hairline px-4 pt-3 pb-3.5">
            <div className="flex flex-wrap gap-1.5">
              {quick.map((q) => (
                <button
                  key={q.label}
                  type="button"
                  onClick={() => ask(q.label, q.reply)}
                  className="cursor-pointer rounded-pill border border-hairline bg-paper-alt px-2.75 py-1.5 text-12 font-semibold text-blue hover:bg-blue hover:text-white"
                >
                  {q.label}
                </button>
              ))}
            </div>
            <form onSubmit={onSubmit} className="flex gap-2">
              <label className="min-w-0 flex-1">
                <span className="sr-only">{t('placeholder')}</span>
                <input
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder={t('placeholder')}
                  className="w-full rounded-pill border border-input px-3.5 py-2.5 font-sans text-14 outline-none focus-visible:border-blue"
                />
              </label>
              <button type="submit" aria-label={t('send')} className="size-10 shrink-0 cursor-pointer rounded-full bg-linear-135/srgb from-orange-bright to-orange-deep text-16 text-white">
                ➤
              </button>
            </form>
          </div>
        </div>
      ) : null}
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        title={t('open')}
        className="pointer-events-auto flex h-14 shrink-0 cursor-pointer items-center gap-2.5 rounded-pill bg-linear-135/srgb from-blue to-blue-deep pr-5.5 pl-4.5 font-sans text-16 font-semibold whitespace-nowrap text-white shadow-fab-blue transition-transform duration-200 hover:-translate-y-0.5 hover:scale-103"
      >
        <span aria-hidden className="text-20">
          {open ? '×' : '💬'}
        </span>
        {t('open')}
      </button>
    </div>
  );
}
