'use client';

interface StepperProps {
  /** Numeric value, for disabling the buttons at the limits. */
  value: number;
  /** The same value, already formatted for the page's language. */
  display: string;
  min?: number;
  max: number;
  onDecrease: () => void;
  onIncrease: () => void;
  decreaseLabel: string;
  increaseLabel: string;
  size?: 'search' | 'detail';
}

/**
 * − value + control. Callers pass store actions that use functional updates, so rapid clicks are
 * never lost.
 */
export function Stepper({ value, display, min = 1, max, onDecrease, onIncrease, decreaseLabel, increaseLabel, size = 'search' }: StepperProps) {
  const box = size === 'search' ? 'h-11 w-full' : 'h-10.5 w-33';
  const button = size === 'search' ? 'w-10' : 'w-9.5';
  const text = size === 'search' ? 'text-15' : 'text-16';
  return (
    <span className={`flex items-center overflow-hidden rounded-11 border border-input bg-white ${box}`}>
      <button
        type="button"
        onClick={onDecrease}
        disabled={value <= min}
        aria-label={decreaseLabel}
        className={`h-full ${button} cursor-pointer text-18 text-blue disabled:cursor-not-allowed disabled:opacity-40`}
      >
        −
      </button>
      <output aria-live="polite" className={`flex-1 text-center font-display font-bold text-ink ${text}`}>
        {display}
      </output>
      <button
        type="button"
        onClick={onIncrease}
        disabled={value >= max}
        aria-label={increaseLabel}
        className={`h-full ${button} cursor-pointer text-18 text-blue disabled:cursor-not-allowed disabled:opacity-40`}
      >
        +
      </button>
    </span>
  );
}
