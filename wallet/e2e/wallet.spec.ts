import { expect, test, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8, §10: the super admin opens the wallet with the password (no
 * authenticator code since 2026-10-09);
 * cash in with a saved reference; a deal with an advance and a payment; a reversal with its reason. An admin can't get in.
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

test("cash in with a saved reference, a deal with its advance and a payment, and a reversal with its reason", async ({
  page,
}) => {
  await signIn(page, "owner@e2e.test");
  await expect(page.getByTestId("wallet-balance")).toBeVisible({
    timeout: 15_000,
  });

  // A business of the proprietor's, added in the wallet itself; a saved reference clicked into the form.
  const form = page.getByTestId("cash-form");
  await page.getByText("Add a business or source").click();
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

  // A deal: the advance can't exceed the total; it goes into the balance, and a payment settles what is due.
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
  await page
    .getByTestId("deals")
    .getByRole("button", { name: "Record payment" })
    .click();
  await expect(page.getByTestId("deals")).toContainText("Fully paid");
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 2,00,000");

  // Reverse the cash in: an entry the other way with the reason; nothing is deleted.
  const history = page.getByTestId("history");
  await history.getByRole("button", { name: "Reverse “Profit share”" }).click();
  const dialog = page.getByRole("dialog", { name: "Reverse BDT 1,20,000" });
  await dialog.getByLabel("Reason").fill("Entered in the wrong month");
  await dialog.getByRole("button", { name: "Reverse" }).click();
  await expect(history).toContainText("Reversal · Entered in the wrong month");
  await expect(page.getByTestId("wallet-balance")).toHaveText("BDT 80,000");
  await expect(history.getByText("Reversed", { exact: true })).toBeVisible();

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
