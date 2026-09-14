interface SectionHeadingProps {
  heading: string;
  /** Bangla pages carry an English sub-line under each heading; English pages leave it empty. */
  lede?: string;
  tone?: 'light' | 'dark';
  className?: string;
}

/** Orange rule, section heading, optional lede — the heading block every website section opens with. */
export function SectionHeading({ heading, lede, tone = 'light', className = '' }: SectionHeadingProps) {
  return (
    <div data-reveal className={`flex flex-col gap-1.5 ${className}`}>
      <span aria-hidden className="mb-4 h-0.5 w-7 bg-orange-deep" />
      <h2 className="text-section">{heading}</h2>
      {lede ? (
        <p className={`font-display text-lede ${tone === 'dark' ? 'text-on-navy-soft' : 'text-muted-label'}`}>{lede}</p>
      ) : null}
    </div>
  );
}
