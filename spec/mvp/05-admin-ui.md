# Admin UI

**Risk Tier**: 🟡 Yellow — capability-gated UI over existing services; list tables

## User Stories

**As a** site admin  
**I want to** manage subscribers, campaigns, and see queue progress in wp-admin  
**So that** I can operate the newsletter without WP-CLI

**Scenario**: Plugin menu is visible to admins only
  **Given** a user with `manage_options`
  **When** they open wp-admin
  **Then** they see a top-level or Settings submenu for WP Resend Newsletter
  **Given** a user without `manage_options`
  **When** they open wp-admin
  **Then** the menu and screens are not accessible (403 / `wp_die` if URL guessed)

**Scenario**: Subscribers list table
  **Given** existing subscribers in multiple statuses
  **When** admin opens Subscribers
  **Then** they see email, status, dates, and can filter by status
  **And** bulk unsubscribe (with nonce) works

**Scenario**: Campaigns list and editor
  **Given** draft and sending campaigns
  **When** admin opens Campaigns
  **Then** they can create/edit drafts and trigger “Queue send” for ready campaigns
  **And** all forms use nonces and capability checks

**Scenario**: Queue status view
  **Given** jobs in pending/sent/failed
  **When** admin opens Queue / Campaign detail
  **Then** counts by status are shown and failed jobs show last error (no API key leaked)

## Development Tasks

### Data Layer
- [x] None new (uses repositories from 02–04)

### API Layer
- [x] Admin-post / AJAX handlers with nonce + `manage_options` → `/wp-ajax` where needed

### Interface Layer
- [x] Menu registration in `Bootstrap`
- [x] Subscribers `WP_List_Table` → `/list-table`
- [x] Campaigns list + edit form
- [x] Settings screen already from 01 — ensure menu IA is consistent

### Integration Layer
- [x] Smoke tests or admin integration tests for capability gates → (TDD selective)

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Login as admin | Menu visible |
| 2 | Login as author | Menu hidden; direct URL denied |
| 3 | Create campaign via UI; queue send | Jobs appear; counts update |
