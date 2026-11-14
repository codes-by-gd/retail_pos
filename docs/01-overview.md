# 01 · Overview

## Goal
A **very easy to use** Point of Sale for a **clothing store**, covering the whole retail cycle: buying stock from suppliers, managing inventory by size and colour, billing customers quickly at the counter, and reporting on sales, profit and GST.

## Domain: clothing retail
What makes apparel different, and how the system handles it:

| Need | How it's handled |
|---|---|
| Same garment in many sizes and colours | Products have **variants built from attributes** — Colour × Size / Shirt Size / Waist / Kids Age. Each combination has its own SKU, barcode, price and stock. |
| Brand style codes on tags | **Article / style no.** on every product, searchable and printed on labels |
| Fabric | **Material** field (Cotton, Linen, Silk…) |
| GST depends on price | **Garments rate**: 5% when one piece sells for up to ₹2,500 (taxable value), 18% above — chosen per piece at billing |
| Sarees, fabric by the metre, accessories, alterations | Single-item products; **metre** unit allows decimals; services don't track stock |
| Seeing stock at a glance | **Stock matrix** (colour × size) per branch on the product page |
| Price tags | Garment tags with brand, product, size/colour, article no., MRP, price, barcode; hang-tag preset |
| Departments | Category tree: Men, Women, Kids, Accessories, Fabrics (+ sub-categories) |

## Scope

| In scope | Out of scope (for now) |
|---|---|
| One business with **multiple branches** (stores/warehouses) | Multi-tenant SaaS (many unrelated businesses) |
| Indian **GST** billing and reports | Other countries' tax systems |
| Browser-based, works on LAN or cloud server | Offline mode (possible later phase as a PWA) |
| Thermal (58/80 mm) and A4 invoices, barcode labels | Direct ESC/POS printer drivers (later option) |

## Modules

| Module | Purpose | Doc |
|---|---|---|
| POS billing | Fast counter billing: scan, pay, print | [pos-billing](modules/pos-billing.md) |
| Inventory | Products, variants, barcodes, labels, stock | [inventory](modules/inventory.md) |
| Orders | Sales, purchases, quotations, returns | [orders](modules/orders.md) |
| Contacts | Customers and suppliers, ledgers | [contacts](modules/contacts.md) |
| Users | Staff accounts, roles, permissions, branches | [users](modules/users.md) |
| Reports | Sales, stock, profit, GST reports | [reports](modules/reports.md) |
| Notifications | Low stock, expiry, cash mismatch alerts | [notifications](modules/notifications.md) |
| Dashboard | KPIs and charts at a glance | [dashboard](modules/dashboard.md) |
| Settings | Business profile, branches, invoice & POS preferences | [settings](modules/settings.md) |
| Expenses | Shop expenses for true profit | [expenses](modules/expenses.md) |

## Who uses it

| Role | Typical work |
|---|---|
| **Admin** (owner) | Everything, including settings, roles and all branches |
| **Manager** | Runs a store: users, purchases, reports, approvals |
| **Cashier** | Billing at the counter, customers, opening/closing the register |
| **Stock keeper** | Products, stock adjustments/transfers, purchases, labels |

Roles are editable; see [Roles & permissions](05-roles-and-permissions.md).

## Glossary

| Term | Meaning |
|---|---|
| **Branch** | A store location. Stock, registers and invoice numbers are per branch. |
| **Register / session** | A cash counter; a session runs from opening float to closing count (Z-report). |
| **Hold / park** | Saving an unfinished bill to serve another customer, then recalling it. |
| **GRN** | Goods Received Note — recording stock that arrived from a supplier. |
| **Credit note** | Document issued when a customer returns goods. |
| **HSN / SAC** | Government classification codes for goods / services, required on GST invoices. |
| **GSTIN** | 15-character GST registration number. First two digits = state code. |
| **Stock ledger** | Every stock movement (sale, purchase, adjustment, transfer, return) with its reference. |
