# CRM Project Update - 2026-02-13 (Settings Restriction)

## Tasks Completed
- Restricted the "Settings" page for technicians.
- Hidden "System & DB", "Integrations", "Company Data", and "Admin Management" tabs for technician accounts.
- Technicians now only see the "Staff" tab (restricted to their own profile).
- Secured backend logic in `settings.php`:
  - Technicians cannot submit forms for company data, integrations, system settings, admin passwords, or add/delete staff.
  - Technicians can only edit their own profile (password, Telegram ID).
  - Important fields (role, active status, rate, username) are protected from modification by technicians even if the form is tampered with.

## Technical Details
- Added `$is_admin_user` check based on `hasPermission('admin_access')`.
- Modified tab navigation and tab content to use conditional rendering.
- Implemented field-level protection in `edit_tech` logic to re-verify current database values for sensitive fields when a non-admin submits the form.
