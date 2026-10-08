import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { useTranslation } from "react-i18next";

import { useFormat } from "./lib/format";
import { actions, useMembers, type Member } from "./lib/queries";
import { Card, Notice, Pending } from "./ui";

type Action = { kind: "revoke"; member: Member };

/**
 * Access (docs/personal-wallets.md), the super admin's alone: give a personal wallet to the staff they choose, or take it
 * away. It shows who has a wallet, never what is in one.
 */
export function Access({ me }: { me: { email: string } }) {
  const { t } = useTranslation();
  const { date } = useFormat();
  const client = useQueryClient();
  const members = useMembers();
  const [confirming, setConfirming] = useState<Action | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const change = useMutation({
    mutationFn: ({
      kind,
      member,
    }: {
      kind: "grant" | "revoke";
      member: Member;
    }) =>
      kind === "grant"
        ? actions.grant(member.staff_id)
        : actions.revoke(member.staff_id),
    onSuccess: (response, { kind, member }) => {
      client.setQueryData(["members"], response.data);
      setDone(
        t(kind === "grant" ? "access.given" : "access.taken", {
          name: member.name,
        }),
      );
      setConfirming(null);
    },
  });

  if (members.isPending || members.isError)
    return <Pending error={members.error} />;

  return (
    <Card>
      <div className="flex flex-col gap-1">
        <h2 className="m-0 text-18 font-bold">{t("access.title")}</h2>
        <p className="m-0 text-13 leading-1.55 text-wallet-muted">
          {t("access.note")}
        </p>
      </div>
      {done ? <Notice tone="in">{done}</Notice> : null}
      {change.error ? <Notice tone="out">{change.error.message}</Notice> : null}
      {members.data.length === 0 ? (
        <p className="m-0 text-13 text-wallet-muted">{t("access.empty")}</p>
      ) : null}
      <ul
        className="m-0 flex list-none flex-col gap-2.5 p-0"
        data-testid="wallet-members"
      >
        {members.data.map((member) => (
          <li
            key={member.staff_id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-14 border border-wallet-line bg-wallet-bar px-4 py-3"
          >
            <span className="flex min-w-0 flex-col gap-0.5">
              <strong className="text-14">
                {member.name}
                {member.email === me.email ? (
                  <span className="ml-1.5 text-12 font-normal text-wallet-muted">
                    ({t("access.you")})
                  </span>
                ) : null}
              </strong>
              <span className="text-12 break-all text-wallet-muted">
                {member.email}
              </span>
              <span className="text-12 text-wallet-faint">
                {member.super_admin
                  ? t("access.superAdmin")
                  : member.has_wallet
                    ? `${t("access.hasWallet")}${member.granted_at ? ` · ${t("access.since", { date: date(member.granted_at) })}` : ""}`
                    : t("access.noWallet")}
              </span>
            </span>
            {member.super_admin ? null : (
              <span className="flex flex-wrap gap-2">
                {member.has_wallet ? (
                  <button
                    type="button"
                    className={`${secondary} text-wallet-out-soft`}
                    disabled={change.isPending}
                    onClick={() => setConfirming({ kind: "revoke", member })}
                  >
                    {t("access.takeAway")}
                  </button>
                ) : (
                  <button
                    type="button"
                    className={`${secondary} border-wallet-violet text-wallet-lavender`}
                    disabled={change.isPending}
                    onClick={() => change.mutate({ kind: "grant", member })}
                  >
                    {t("access.give")}
                  </button>
                )}
              </span>
            )}
            {confirming?.member.staff_id === member.staff_id ? (
              <div
                role="alertdialog"
                aria-label={t("access.takeAway")}
                className="flex w-full flex-col gap-2.5 rounded-12 border border-wallet-out-soft/40 bg-wallet-out/10 p-3"
              >
                <p className="m-0 text-13 leading-1.55">
                  {t("access.confirmTakeAway", { name: member.name })}
                </p>
                <span className="flex gap-2">
                  <button
                    type="button"
                    className={secondary}
                    onClick={() => setConfirming(null)}
                  >
                    {t("access.cancel")}
                  </button>
                  <button
                    type="button"
                    className={`${secondary} border-wallet-out-soft text-wallet-out-soft`}
                    disabled={change.isPending}
                    onClick={() =>
                      change.mutate({ kind: confirming.kind, member })
                    }
                  >
                    {t("access.confirm")}
                  </button>
                </span>
              </div>
            ) : null}
          </li>
        ))}
      </ul>
    </Card>
  );
}

const secondary =
  "cursor-pointer rounded-pill border border-wallet-input bg-transparent px-3.5 py-1.75 text-13 font-semibold text-wallet-text disabled:cursor-not-allowed disabled:opacity-55";
