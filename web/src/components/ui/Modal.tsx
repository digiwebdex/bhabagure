'use client';

import { useEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

interface ModalProps {
  open: boolean;
  onClose: () => void;
  labelledBy: string;
  size: 'sm' | 'md' | 'lg';
  /** Stacking order from the prototype: detail 68, booking 70, sign-in 74. */
  layer: 'detail' | 'booking' | 'auth';
  children: ReactNode;
}

const widths = { sm: 'max-w-modal-sm', md: 'max-w-modal-md', lg: 'max-w-modal-lg' } as const;
const layers = { detail: 'z-68', booking: 'z-70', auth: 'z-74' } as const;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Backdrop click or × closes; clicks inside stop propagation. Escape closes, focus stays inside
 * while open and returns to the opener afterwards, and the page behind doesn't scroll.
 */
export function Modal({ open, onClose, labelledBy, size, layer, children }: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    if (!open) return;
    const opener = document.activeElement as HTMLElement | null;
    const root = document.documentElement;
    const previousOverflow = root.style.overflow;
    root.style.overflow = 'hidden';

    const dialog = dialogRef.current;
    (dialog?.querySelector<HTMLElement>('[data-autofocus]') ?? dialog)?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onCloseRef.current();
        return;
      }
      if (event.key !== 'Tab' || !dialog) return;
      const focusable = [...dialog.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => el.offsetParent !== null);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey);

    return () => {
      document.removeEventListener('keydown', onKey);
      root.style.overflow = previousOverflow;
      opener?.focus?.();
    };
  }, [open]);

  if (!open) return null;

  return createPortal(
    <div
      role="presentation"
      onClick={onClose}
      className={`fixed inset-0 ${layers[layer]} flex animate-modal-in items-center justify-center bg-scrim/55 p-fluid-12-28 backdrop-blur-xs`}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelledBy}
        tabIndex={-1}
        onClick={(event) => event.stopPropagation()}
        className={`flex max-h-modal w-full ${widths[size]} flex-col overflow-hidden rounded-fluid bg-white text-ink shadow-modal outline-none`}
      >
        {children}
      </div>
    </div>,
    document.body,
  );
}

/** The × button used in every modal header. */
export function ModalClose({ onClick, label, size = 'md' }: { onClick: () => void; label: string; size?: 'sm' | 'md' }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className={`flex shrink-0 cursor-pointer items-center justify-center rounded-10 bg-paper-alt text-ink ${size === 'sm' ? 'size-8 text-17' : 'size-8.5 text-18'}`}
    >
      ×
    </button>
  );
}
