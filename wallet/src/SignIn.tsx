import { useId, useState, type FormEvent, type KeyboardEvent } from "react";
import { useTranslation } from "react-i18next";

import { ApiError } from "./lib/api";
import { actions } from "./lib/queries";
import { Notice, PrimaryButton, Shell, inputClass } from "./ui";

/** Why the sign-in is showing again: the person signed out, or the session ran out while the wallet was open. */
export type SignInNotice = "signed_out" | "session_ended" | null;

/**
 * The admin panel's "Forgot password?" page beside this wallet (wallet.bhabaghure.com.bd → admin.bhabaghure.com.bd): the
 * wallet signs in with the staff password, so that is where it is reset.
 */
function forgotPasswordUrl(): string {
  const { protocol, host } = window.location;
  return host.startsWith("wallet.")
    ? `${protocol}//admin.${host.slice("wallet.".length)}/forgot-password`
    : "https://admin.bhabaghure.com.bd/forgot-password";
}

/**
 * The wallet's sign-in (docs/personal-wallets.md): the staff email and password (with "Forgot password?", reset on the
 * admin panel) open the wallet. There is no authenticator code since 2026-10-09, at the owner's request.
 */
export function SignIn({
  onSignedIn,
  notice = null,
}: {
  onSignedIn: () => void;
  notice?: SignInNotice;
}) {
  const { t } = useTranslation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(
    notice ? t(`signIn.notices.${notice}`) : null,
  );
  const [busy, setBusy] = useState(false);

  const submitPassword = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    void actions
      .password({ email: email.trim(), password })
      .then(() => {
        setMessage(null);
        onSignedIn();
      })
      .catch((e: unknown) =>
        setError(e instanceof ApiError ? e.message : t("errors.network")),
      )
      .finally(() => setBusy(false));
  };

  return (
    <Shell>
      <div className="mx-auto flex w-full max-w-[1080px] flex-wrap items-start justify-center gap-x-12 gap-y-5 sm:py-4">
        <aside className="hidden max-w-[470px] flex-[1_1_320px] flex-col gap-2.5 pt-1.5 sm:flex">
          <span className="font-display text-12 font-bold tracking-[0.16em] text-wallet-violet uppercase">
            {t("signIn.locksEyebrow")}
          </span>
          <h2 className="m-0 font-display text-[32px] leading-1.15 font-bold tracking-[-0.01em]">
            {t("signIn.locksTitle")}
          </h2>
          <p className="m-0 text-15 leading-1.6 text-wallet-muted">
            {t("signIn.locksNote")}
          </p>
          <p className="m-0 pt-3 text-13 leading-1.6 text-wallet-muted">
            {t("signIn.sessionNote")}
          </p>
        </aside>
        <section className="flex w-full max-w-[460px] flex-[1_1_380px] flex-col gap-4.5 rounded-18 border border-wallet-line bg-wallet-surface p-[clamp(20px,4vw,28px)]">
          {message ? <Notice tone="violet">{message}</Notice> : null}
          <PasswordForm
            email={email}
            password={password}
            onEmail={setEmail}
            onPassword={setPassword}
            onSubmit={submitPassword}
            busy={busy}
            error={error}
          />
        </section>
      </div>
    </Shell>
  );
}

function PasswordForm({
  email,
  password,
  onEmail,
  onPassword,
  onSubmit,
  busy,
  error,
}: {
  email: string;
  password: string;
  onEmail: (value: string) => void;
  onPassword: (value: string) => void;
  onSubmit: (event: FormEvent) => void;
  busy: boolean;
  error: string | null;
}) {
  const { t } = useTranslation();
  const id = useId();
  const [shown, setShown] = useState(false);
  const [capsLock, setCapsLock] = useState(false);
  const watchCaps = (event: KeyboardEvent<HTMLInputElement>) =>
    setCapsLock(event.getModifierState("CapsLock"));

  return (
    <form className="flex flex-col gap-4" onSubmit={onSubmit}>
      <h1 className="m-0 font-display text-24 font-bold">
        {t("signIn.title")}
      </h1>
      <div className="flex flex-col gap-1.5">
        <label
          htmlFor={`${id}-email`}
          className="text-13 font-semibold text-wallet-muted"
        >
          {t("signIn.email")}
        </label>
        <input
          id={`${id}-email`}
          className={`${inputClass} min-h-12 text-15`}
          type="email"
          autoComplete="username"
          value={email}
          onChange={(e) => onEmail(e.target.value)}
          required
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <label
          htmlFor={`${id}-password`}
          className="text-13 font-semibold text-wallet-muted"
        >
          {t("signIn.password")}
        </label>
        <div className="flex gap-2">
          <input
            id={`${id}-password`}
            className={`${inputClass} min-h-12 text-15`}
            type={shown ? "text" : "password"}
            autoComplete="current-password"
            value={password}
            onChange={(e) => onPassword(e.target.value)}
            onKeyDown={watchCaps}
            onKeyUp={watchCaps}
            aria-describedby={`${id}-password-note`}
            required
          />
          <button
            type="button"
            onClick={() => setShown((value) => !value)}
            aria-label={
              shown ? t("signIn.hidePassword") : t("signIn.showPassword")
            }
            aria-pressed={shown}
            className="min-h-12 min-w-16 shrink-0 cursor-pointer rounded-10 border border-wallet-input bg-wallet-bar text-13 font-semibold text-wallet-lilac"
          >
            {shown ? t("signIn.hide") : t("signIn.show")}
          </button>
        </div>
        {capsLock ? (
          <span
            id={`${id}-password-note`}
            role="status"
            className="text-12 font-semibold text-wallet-due"
          >
            {t("signIn.capsLock")}
          </span>
        ) : (
          <span
            id={`${id}-password-note`}
            className="text-12 text-wallet-muted"
          >
            {t("signIn.passwordHint")}
          </span>
        )}
      </div>
      {error ? <Notice tone="out">{error}</Notice> : null}
      <PrimaryButton
        type="submit"
        className="min-h-12 text-15"
        disabled={busy || !email || !password}
      >
        {busy ? t("signIn.checking") : t("signIn.open")}
      </PrimaryButton>
      <p className="m-0 text-13 leading-1.6 text-wallet-muted">
        {t("signIn.forgot")}{" "}
        <a
          href={forgotPasswordUrl()}
          target="_blank"
          rel="noopener noreferrer"
          className="font-semibold"
        >
          {t("signIn.forgotLink")}
        </a>
      </p>
    </form>
  );
}
