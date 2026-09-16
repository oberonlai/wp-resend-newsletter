# Campaigns

**Risk Tier**: 🟡 Yellow — content CRUD; send execution in 04 via **Broadcasts** (not transactional API)

## User Stories

**As a** site admin  
**I want to** create and edit newsletter campaigns  
**So that** I can prepare subject and body before starting a Broadcast send

**Scenario**: Create draft campaign
  **Given** an admin with `manage_options`
  **When** they create a campaign with subject `Hello`, HTML body `<p>Hi</p>`, text body `Hi`
  **Then** a row is stored with status `draft` and those fields persisted

**Scenario**: Empty subject rejected
  **Given** an admin on the campaign editor
  **When** they submit with an empty subject
  **Then** validation fails and no incomplete row is published as sendable

**Scenario**: Bodies include Resend unsubscribe placeholder guidance
  **Given** a campaign editor
  **When** admin prepares HTML/text for a real send
  **Then** docs/UI recommend including `{{{RESEND_UNSUBSCRIBE_URL}}}` (Resend Broadcast unsubscribe)
  **And** campaign content is stored locally; delivery still goes through Broadcasts in area 04

**Scenario**: Transition draft → ready (or scheduled)
  **Given** a valid draft campaign
  **When** admin marks it ready to send (without starting the Broadcast yet)
  **Then** status becomes `ready` (or `scheduled` if a future time is set)

**Scenario**: Non-admin cannot mutate campaigns
  **Given** a user without `manage_options`
  **When** they POST a campaign create/update
  **Then** the request is rejected and no row changes

**Scenario**: Campaign send is never transactional batch
  **Given** a ready campaign
  **When** admin starts send (area 04)
  **Then** the pipeline uses Resend **Broadcasts** + Segment contacts
  **And** it must **not** call `ResendClient::send_batch` / transactional `emails` for the blast

## Development Tasks

### Data Layer
- [x] Create `{prefix}wprn_campaigns` → `/custom-table`
  - Columns (min): `id`, `subject`, `body_html`, `body_text`, `status`, `scheduled_at`, `resend_broadcast_id` (nullable), `created_by`, `created_at`, `updated_at`
- [x] `CampaignRepository`

### API Layer
- [x] Application service for create/update/status transitions
- [x] **No** Resend transactional send calls in this area; Broadcast create+send lives in 04

### Interface Layer
- [x] Covered primarily in area 05 (Admin UI); ensure model supports list/edit
- [x] Hint for `{{{RESEND_UNSUBSCRIBE_URL}}}` and verified from-domain (`news.oberonlai.blog`)

### Integration Layer
- [x] Activator creates table
- [x] Tests for validation and status transitions → (TDD)

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Create campaign with subject + bodies | Draft saved |
| 2 | Clear subject and save | Validation error |
| 3 | Mark ready | Status `ready` |
