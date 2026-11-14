# Users

**Status:** ✅ core done (Phase 0)
**Purpose:** Staff accounts, what they can do (role) and where (branches).

## Features
- ✅ User list with search (name/email/phone), role and status filters, pagination
- ✅ Create/edit user: name, email (login), phone, password, POS PIN (4–6 digits, hashed), active flag
- ✅ One role per user; branch assignment (required for non-admins)
- ✅ Roles list; create/edit roles with permissions grouped by module (select-all per group)
- ✅ Inactive users cannot log in; last login time recorded
- ✅ Changes to users and branches are written to the audit log
- ⏳ POS lock screen & quick cashier switch with PIN (P3)
- ⏳ Audit log viewer (P6)
- ⏳ Per-user notification preferences (P6)

## Screens
| Screen | Route | Page |
|---|---|---|
| Users | `users.index` | `Pages/Users/Index.vue` |
| New / edit user | `users.create`, `users.edit` | `Pages/Users/Form.vue` |
| Roles | `roles.index` | `Pages/Roles/Index.vue` |
| New / edit role | `roles.create`, `roles.edit` | `Pages/Roles/Form.vue` |
| My profile | `profile.edit` | `Pages/Profile/Edit.vue` |

## Business rules
- Only Admins can create, edit or assign the Admin role.
- You cannot deactivate yourself or remove your own Admin role; the last active Admin cannot be demoted.
- Leaving password/PIN blank on edit keeps the current value.
- Users are deactivated, never deleted (they will be referenced by sales and stock history).
- The Admin role cannot be edited or deleted; a role with users cannot be deleted.

## Permissions
`users.view`, `users.manage`, `roles.manage` — see [Roles & permissions](../05-roles-and-permissions.md).

## Code
`app/Modules/Users/Http/Controllers/{User,Role}Controller`, `Http/Requests/UserRequest`, `app/Models/User.php`, `app/Support/Permissions.php`, `database/seeders/RolesAndPermissionsSeeder.php`.
