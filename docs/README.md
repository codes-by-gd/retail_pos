# Retail POS — Project Documentation

A web-based Point of Sale and retail management system for a **clothing / apparel store** (one business, multiple branches), built for Indian GST.

## How these docs are organised

| Doc | What it covers |
|---|---|
| [01 · Overview](01-overview.md) | Vision, scope, users, key decisions, glossary |
| [02 · Architecture](02-architecture.md) | Tech stack, folder layout, coding conventions, core rules |
| [03 · Getting started](03-getting-started.md) | Local setup, commands, demo logins, switching to MySQL |
| [04 · Database](04-database.md) | Tables that exist today and the planned schema |
| [05 · Roles & permissions](05-roles-and-permissions.md) | Permission catalogue, default roles, how to add permissions |
| [06 · GST](06-gst.md) | How Indian GST is applied in billing and reports |
| [07 · Roadmap](07-roadmap.md) | Build phases and their status |
| [08 · Decision log](08-decisions.md) | Why things are the way they are |
| [09 · Changelog](09-changelog.md) | What changed, phase by phase |
| [Modules](modules/README.md) | One page per functional module: features, screens, rules, status |

## Keeping the docs current

- When a feature ships, tick it in the module page, update [Roadmap](07-roadmap.md) and add a line to the [Changelog](09-changelog.md).
- New tables or columns → [Database](04-database.md). New permissions → [Roles & permissions](05-roles-and-permissions.md).
- A significant choice (library, pattern, business rule) → a new entry in the [Decision log](08-decisions.md).
- Status markers used everywhere: ✅ built · 🚧 in progress · ⏳ planned.
