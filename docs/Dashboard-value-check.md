# Test And Check Editor Dashboard Values

**Creator by:** Jahan Haidari (BA)

**Sprint:** 2

**Task:** Work out expected dashboard values and check the pages

---

## What Was Checked

I calculated the expected values for the Editor Monitoring Dashboard directly from the test data using SQL queries, then compared them with what the dashboard shows. All checks used the definitions document as the standard.

---

## Results

### Overview Page Live Section

| Dashboard Value | Expected Value | Match |
|-----------------|----------------|-------|
| Total reviewers | 36 | Yes |
| Review Group Leader | 7 | Yes |

**How I calculated:**
- Total reviewers: users in RGM (group 20) or RGL (group 19) groups, excluding disabled accounts. One disabled user (test_rgm_29) was correctly excluded, giving 36.
- Review Group Leaders: users in group 19. That is 7 users.

---

### Overview Page Activity Section (2026)

| Dashboard Value | Expected Value | Match              |
|-----------------|----------------|--------------------|
| Review groups | 20 | Yes                |
| Completed | 15 | **No should be 8** |

**How I calculated Review groups:**
Finalised polls (status 1) with a meeting time in 2026. That is 20 groups.

**How I calculated Completed:**
Of those 20 groups:

| Status | Count |
|--------|-------|
| Completed (decided, not cancelled) | 8 |
| Cancelled (latest decision = cancel review round) | 5 |
| Current (no editorial decision yet) | 7 |
| **Total** | **20** |

8 + 5 + 7 = 20. The maths checks out.

---

## The Mismatch

**Completed shows 15 on the dashboard, but only 8 groups are actually completed.**

The dashboard appears to be counting all non-cancelled groups (8 completed + 7 current = 15) instead of only those that have been decided.

The definitions document says:

> Completed: Review groups selected for or led, decided and not cancelled.

The dashboard is not checking for the editorial decision.

---

## Evidence

Query used to list each group's status:

```sql
SELECT
  s.session_id,
  s.submission_id,
  sl.start_time_utc AS meeting_time,
  CASE
    WHEN EXISTS (SELECT 1 FROM edit_decisions d WHERE d.submission_id = s.submission_id AND d.decision = 6 AND d.date_decided > sl.start_time_utc) THEN 'CANCELLED'
    WHEN EXISTS (SELECT 1 FROM edit_decisions d WHERE d.submission_id = s.submission_id AND d.decision IN (1,2,3,4,5,7) AND d.date_decided > sl.start_time_utc) THEN 'COMPLETED'
    ELSE 'CURRENT (not decided)'
  END AS status
FROM group_review_sessions s
LEFT JOIN group_review_slots sl ON sl.slot_id = s.selected_slot_id
WHERE s.status = 1
  AND YEAR(sl.start_time_utc) = 2026
ORDER BY status, s.submission_id;