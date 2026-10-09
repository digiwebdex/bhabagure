import { expect, test, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8, §10: the super admin opens the wallet with the password (no
 * authenticator code since 2026-10-09);
 * cash in with a saved reference; a deal with an advance, edited, and a payment added; an entry edited, viewed with
 * what changed, and deleted (docs/wallet-edits.md). An admin can't get in.
 */
test.describe.configure({ mode: "serial" });

const password = () =>
  (
    JSON.parse(
      readFileSync(resolve(import.meta.dirname, ".state/staff.json"), "utf8"),
    ) as { password: string }
  ).password;

async function signIn(page: Page, email: string) {
  await page.goto("/");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password());
  await page.getByRole("button", { name: "Open the wallet" }).click();
}

test("the super admin opens the wallet with the password alone, and an admin without a wallet is refused", async ({
  page,
}) => {
  // The sign-in page (2026-10-02): "Forgot password?" goes to the admin panel's reset, and the password can be shown.
  await page.goto("/");
  await expect(
    page.getByRole("link", { name: "Reset it on the admin panel" }),
  ).toHaveAttribute("href", /\/forgot-password$/);
  const passwordBox = page.getByLabel("Password", { exact: true });
  await passwordBox.fill("typed");
  await page.getByRole("button", { name: "Show password" }).click();
  await expect(passwordBox).toHaveAttribute("type", "text");
  await page.getByRole("button", { name: "Hide password" }).click();
  await expect(passwordBox).toHaveAttribute("type", "password");

  await signIn(page, "admin@e2e.test");
  await expect(page.getByRole("alert")).toContainText(
    "That email and password don’t open the wallet.",
    { timeout: 15_000 },
  );

  await signIn(page, "owner@e2e.test");
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 0", {
    timeout: 15_000,
  });
  await expect(page.getByText("Private · only you see it")).toBeVisible();
});

test("cash in with a saved reference; a deal edited and paid; an entry edited, viewed with its history and deleted", async ({
  page,
}) => {
  await signIn(page, "owner@e2e.test");
  await expect(page.getByTestId("wallet-balance")).toBeVisible({
    timeout: 15_000,
  });

  // A business of the proprietor's, added in the wallet itself; a saved reference clicked into the form.
  const form = page.getByTestId("cash-form");
  await page.getByText("Add or edit a business or source").click();
  await page.getByLabel("Business or source name").fill("Family business");
  await page.getByRole("button", { name: "+ Add" }).click();
  await expect(form.getByLabel("Which business or source")).toContainText(
    "Family business",
  );
  await form
    .getByLabel("Which business or source")
    .selectOption({ label: "Family business" });
  await form.getByLabel("Save a new reference").fill("Profit share");
  await form.getByRole("button", { name: "+ Save" }).click();
  await expect(
    form.getByLabel("Reference · what this money is for"),
  ).toHaveValue("Profit share");
  await form.getByLabel("Amount (BDT)").fill("120000");
  await form.getByRole("button", { name: "Add cash in" }).click();
  await expect(page.getByText("Cash in BDT 1,20,000 recorded")).toBeVisible();
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 1,20,000");
  await expect(page.getByTestId("breakdown")).toContainText("Family business");

  // Renamed in the list: the entry already made and the breakdown show the new name.
  const sources = page.getByTestId("sources");
  await sources
    .getByRole("button", { name: "Rename “Family business”" })
    .click();
  await sources.getByLabel("New name").fill("Family business Ltd");
  await sources.getByRole("button", { name: "Save" }).click();
  await expect(page.getByTestId("breakdown")).toContainText(
    "Family business Ltd",
  );
  await expect(page.getByTestId("history")).toContainText(
    "Family business Ltd",
  );
  await expect(form.getByLabel("Which business or source")).toContainText(
    "Family business Ltd",
  );

  // A deal: the advance can't exceed the total; it goes into the balance. Then the deal edited and a payment added
  // (docs/wallet-edits.md).
  const deal = page.getByTestId("deal-form");
  await deal.getByLabel("Company or client").fill("Software project");
  await deal.getByLabel("Deal total").fill("80000");
  await deal.getByLabel("Advance").fill("90000");
  await expect(deal).toContainText(
    "The advance can’t be more than the deal total.",
  );
  await deal.getByLabel("Advance").fill("40000");
  await expect(deal).toContainText(
    "Advance BDT 40,000 will be added to your balance · BDT 40,000 stays due",
  );
  await deal.getByRole("button", { name: "Save deal & advance" }).click();
  await expect(
    page.getByText("Deal saved · advance BDT 40,000 added to balance"),
  ).toBeVisible();
  await expect(page.getByTestId("due-total")).toHaveText(
    "Total due BDT 40,000",
  );
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 1,60,000");
  const deals = page.getByTestId("deals");
  await deals
    .getByRole("button", { name: "Edit the deal “Software project”" })
    .click();
  const editDeal = page.getByRole("dialog", {
    name: "Edit the deal “Software project”",
  });
  await editDeal.getByLabel("Deal total").fill("30000");
  await expect(editDeal).toContainText(
    "The total can’t be less than the BDT 40,000 already received.",
  );
  await editDeal.getByLabel("Deal total").fill("90000");
  await editDeal.getByLabel("Reason (optional)").fill("Extra page");
  await editDeal.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Deal “Software project” saved")).toBeVisible();
  await expect(page.getByTestId("due-total")).toHaveText(
    "Total due BDT 50,000",
  );
  await deals
    .getByRole("button", { name: "Add payment to “Software project”" })
    .click();
  const pay = page.getByRole("dialog", {
    name: "Add payment · Software project",
  });
  await expect(pay.getByLabel("Amount (BDT)")).toHaveValue("50000");
  await pay.getByLabel("Amount (BDT)").fill("40000");
  await pay.getByRole("button", { name: "Save payment" }).click();
  await expect(
    page.getByText("Payment BDT 40,000 from Software project recorded"),
  ).toBeVisible();
  await expect(page.getByTestId("due-total")).toHaveText(
    "Total due BDT 10,000",
  );
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 2,00,000");
  await deals
    .getByRole("button", { name: "View the deal “Software project”" })
    .click();
  const viewDeal = page.getByRole("dialog", {
    name: "Deal: Software project",
  });
  await expect(viewDeal.getByTestId("deal-history")).toContainText(
    "Total: BDT 80,000 → BDT 90,000",
  );
  await expect(viewDeal.getByTestId("deal-history")).toContainText(
    "Reason: Extra page",
  );
  await viewDeal.getByRole("button", { name: "Close" }).click();

  // Edit the cash in: the corrected entry takes its place, and the balance follows.
  const history = page.getByTestId("history");
  await history.getByRole("button", { name: "Edit “Profit share”" }).click();
  const edit = page.getByRole("dialog", { name: "Edit “Profit share”" });
  await edit.getByLabel("Amount (BDT)").fill("100000");
  await edit.getByLabel("Reason (optional)").fill("Typed wrong");
  await edit.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Saved · BDT 1,00,000")).toBeVisible();
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 1,80,000");
  await expect(history).toContainText("BDT 1,00,000");
  await expect(history).not.toContainText("BDT 1,20,000");

  // View shows what the edit changed and why; Delete from there, with a reason.
  await history.getByRole("button", { name: "View “Profit share”" }).click();
  const view = page.getByRole("dialog", { name: "Entry: Profit share" });
  await expect(view.getByTestId("entry-versions")).toContainText(
    "Amount: BDT 1,20,000 → BDT 1,00,000",
  );
  await expect(view.getByTestId("entry-versions")).toContainText(
    "Reason: Typed wrong",
  );
  await view.getByRole("button", { name: "Delete" }).click();
  const remove = page.getByRole("dialog", { name: "Delete BDT 1,00,000" });
  await remove
    .getByLabel("Reason (optional)")
    .fill("Entered in the wrong month");
  await remove.getByRole("button", { name: "Delete" }).click();
  await expect(page.getByText("Deleted · BDT 1,00,000")).toBeVisible();
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 80,000");
  await expect(history).not.toContainText("Profit share");

  // "Show edits and deletions" brings both versions back, marked.
  await page.getByRole("button", { name: "Show edits and deletions" }).click();
  await expect(history).toContainText("Replaced by an edit");
  await expect(history).toContainText("Entered in the wrong month");
  await expect(
    history.getByRole("button", { name: "Edit “Profit share”" }),
  ).toHaveCount(0);

  await page.getByRole("button", { name: "Sign out" }).click();
  await expect(
    page.getByRole("heading", { name: "Open the wallet", exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Signed out of the wallet.")).toBeVisible();
});

test("the super admin gives a staff member their own wallet: empty, theirs alone, without Access; taken away, it closes at once", async ({
  page,
  browser,
}) => {
  // docs/personal-wallets.md. The owner (test 2 left BDT 80,000 in their wallet) opens Access.
  await signIn(page, "owner@e2e.test");
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 80,000", {
    timeout: 15_000,
  });
  await page.getByRole("button", { name: "Access" }).click();
  const admin = page
    .getByTestId("wallet-members")
    .locator("li")
    .filter({ hasText: "admin@e2e.test" });
  await expect(admin).toContainText("No wallet");
  await admin.getByRole("button", { name: "Give a wallet" }).click();
  await expect(page.getByText("E2E Admin has a wallet now")).toBeVisible();

  // The staff member, on their own device: their own password, an empty wallet, and no Access.
  const theirs = await (await browser.newContext()).newPage();
  await signIn(theirs, "admin@e2e.test");
  await expect(theirs.getByTestId("wallet-balance")).toHaveText("BDT 0", {
    timeout: 15_000,
  });
  await expect(theirs.locator("main")).not.toContainText("Profit share");
  await expect(theirs.locator("main")).not.toContainText("Software project");
  await expect(theirs.getByRole("button", { name: "Access" })).toHaveCount(0);

  // Taken away: their next request finds them signed out.
  await page.reload();
  await page.getByRole("button", { name: "Access" }).click();
  await admin.getByRole("button", { name: "Take it away" }).click();
  await admin.getByRole("button", { name: "Yes, continue" }).click();
  await expect(
    page.getByText("E2E Admin’s wallet was taken away"),
  ).toBeVisible();
  await theirs.reload();
  await expect(
    theirs.getByRole("heading", { name: "Open the wallet", exact: true }),
  ).toBeVisible({ timeout: 15_000 });
});
