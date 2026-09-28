# Invoice items: Add New Item from packages and products (2026-09-28)

**Asked:** as in the office's old software, **Add New Item** on an invoice shows the packages to pick from — the
website's and the office's own customized ones. If the item isn't there (say "dhaka tour"), two choices: **Add "dhaka
tour" only for this invoice**, or **Add "dhaka tour" as a new product**, which joins the list for next time.

**Decided (the client's answers, 2026-09-28):**
- A package is added at its **per-person price**; staff set the quantity to the number of travellers.
- A new product is saved with its **name and price**, and an optional short description.
- Products are edited or hidden on **Invoices → Products**.
- The list offers **published and draft packages**; archived ones are left out.

## How it works

**Invoice form (changed 2026-09-29 at the client's request):**
- **Find a customer**, clicked, lists the customers straight away (newest first); typing a name or number narrows it.
- A line's **Item** box, clicked, lists everything to pick from; typing narrows it:
  - **Products:** the office's own, each with its price.
  - **Packages:** name, code and length, and the per-person price (the sale price, else the regular price; with a
    size table, its basic/3-star price for two). Drafts are marked.
- Clicking one fills that line with its name, its detail (printed under the item) and its price. Staff then set the
  quantity and change the price if needed.
- **+ Add New Item** adds a new, empty line, and its Item box opens with the list.
- A typed name that isn't on the list offers:
  - **Add "…" only for this invoice:** a line with that name; staff type the price.
  - **Add "…" as a new product:** asks for the price and an optional description, saves the product, and adds it to
    the invoice.

**Invoices → Products** lists the office's products: add, rename, reprice, or hide (a hidden product stays on the invoices
that used it but is no longer offered). Packages are edited in Packages.

## Data and access

- `invoice_products`: `name` (unique), `description`, `unit_price`, `is_active`, `created_by_staff_id`.
- `GET admin/invoice-products` (payments.view, like the invoices): the picker's list. `?manage=1` returns the products
  only, hidden ones included.
- `POST` and `PUT admin/invoice-products` need invoices.manage (admin and accountant).
- Invoice lines are unchanged. A line keeps the name and price it was given, so later changes to a product never alter
  an invoice.

## Tests

- `api/tests/Feature/InvoiceProductTest`:
  - the list: packages with price and detail, drafts marked, archived left out
  - saving a product; a duplicate name refused
  - hiding a product
  - permissions
- `admin/e2e/invoices.spec.ts`:
  - picking a package
  - "as a new product" with its price, offered again and listed on Products
  - "only for this invoice" on an existing draft
