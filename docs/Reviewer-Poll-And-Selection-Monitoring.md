# Reviewer Poll And Selection Monitoring Requirements

**Prepared by:** Jahan Haidari (BA)
**Team:** 77

---

## 1. General Information

This document defines what poll and selection data needs to be visible to different roles in the ASRHE journal system. This feature was discussed in the kickoff meeting and connects to Tool 2, the Editor Dashboard.

### The Core Problem

Currently, Review Group Leaders can only see a list of Review Group Member names who responded to a poll. They have no context about that persons contribution history. They cannot see how often someone responds to polls, how often they are selected, or whether they are being overlooked.

This feature solves that by revealing patterns in reviewer poll responses and selection history that are currently invisible.

---
## Feature 1 Requirements

## 2. Data Fields to Show

| Data Field | Description | Purpose |
|------------|-------------|---------|
| Poll responses | Whether the Review Group Member responded to the poll | Track engagement |
| Response rate | Percentage of polls responded to over time | Identify engaged reviewers |
| Selection rate | Percentage of polls where the Review Group Member was selected | Identify repeatedly overlooked reviewers |
| Selection history | Which submissions the Review Group Member was selected for | Track workload distribution |
| Response versus selection gap | Responded but never or rarely selected | Reveal potential bias or oversight |
| Review load | Number of active reviews per Review Group Member | Identify overburdened reviewers |
| Experience level | Reviewers experience level (from editor maintained records) | Help RGL select approperiate reviewers |
| Methodology background | Reviewers methodology expertise | Help RGL match reviewers to submission |
| Number of reviews done | Total reviews completed | Help RGL assess workload and experience |

---

## 3. Who Needs to See It

| Role | What They See | Why |
|------|---------------|-----|
| Review Group Leader | For reviewers who responded with availability experience level, methodology background, number of reviews done | To select appropriate reviewers |
| Managing Editor | Full overview of all reviewers and all details (availability, selection history, experience, methodology) | To oversee the process and assess reviewer workloads |
| Quality Review Editor | Full overview of all reviewers and all details | To monitor reviewer performance and identify development needs |
| Review Group Member | Their own data | To understand their own engagement patterns |
| Reviewer | Their own data | To track personal contributions |

---

## 4. Decisions the Data Supports

| Decision | Data That Helps |
|----------|-----------------|
| Who is being overlooked | Response rate versus selection rate gap |
| Who is overburdened | Selection history and review load |
| Who is engaged but never selected | Poll response rate and selection rate |
| Who to invite as a reviewer | Poll responses, availability, selection history, and review load |

---

## 5. Problem It Solves

| Problem | Solution |
|---------|----------|
| Review Group Members who are always available but never selected | Dashboard shows response rate versus selection rate so editors can see the gap and correct it |
| Overburdened reviewers | Selection history and review load are visible so editors can balance workload |
| Engagement is invisible | Poll response rate is displayed |
| Bias in reviewer selection | Data reveals patterns so editors can make informed decisions |

---

## 6. When and Where in OJS

| Context | When and Where |
|---------|----------------|
| When selecting reviewers | Feature appears during review setup |
| When viewing reviewer data | Dashboard is accessible from the journal dashboard |

---

## 7. User Stories

### Review Group Leader

As a Review Group Leader, I want to create a poll to invite Review Group Members so that I can select reviewers for the group review.

As a Review Group Leader, I want to see poll responses from Review Group Members so that I can select reviewers based on their availability.

As a Review Group Leader, I want to see a Review Group Members poll response history so that I can identify who is consistently engaged.

As a Review Group Leader, I want to see a Review Group Members selection history so that I can distribute workload fairly.

As a Review Group Leader, I want to see who responds to polls but is never selected so that I can ensure they get opportunities.

### Managing Editor

As a Managing Editor, I want to view poll responses and selection history so that I can oversee the review process.

As a Managing Editor, I want to see patterns across all Review Group Members so that I can identify bias or unfair workload distribution.

As a Managing Editor, I want to see who is overburdened so that I can balance the workload.

### Quality Review Editor

As a Quality Review Editor, I want to see who is engaged but overlooked so that I can recommend them for future reviews.

### Review Group Member

As a Review Group Member, I want to respond to polls so that I can indicate my availability.

As a Review Group Member, I want to see my own response and selection history so that I can understand my engagement patterns.

---

## 8. Roles and Permissions

| Role | Can View | Can Create |
|------|----------|------------|
| Review Group Leader | Experience methodology, review count for reviewers who responded with availabiligy | Polls |
| Managing Editor | All data | No |
| Quality Review Editor | All data | No |
| Review Group Member | Their own data | Poll responses |
| Reviewer | Their own data | No |

---

## 9. Acceptance Criteria

| Number | Acceptance Criteria |
|--------|---------------------|
| 1 | Review Group Leader can create a poll to invite Review Group Members |
| 2 | Review Group Members can respond to the poll with their availability and willingness |
| 3 | Review Group Leader can select Review Group Members based on poll responses |
| 4 | Review Group Leader can view each Review Group Members poll response history |
| 5 | Review Group Leader can view each Review Group Members selection history showing which submissions they were selected for |
| 6 | Review Group Leader can view each Review Group Members response rate as a percentage of polls responded to |
| 7 | Review Group Leader can view each Review Group Members selection rate as a percentage of polls where they were selected |
| 8 | Dashboard highlights Review Group Members with high response rate but low selection rate |
| 9 | Dashboard highlights Review Group Members with high selection rate indicating they may be overburdened |
| 10 | Selection history is stored and visible in the dashboard |
| 11 | Dashboard is accessible to Managing Editors and Quality Review Editors |
| 12 | Dashboard filters by reviewer name |
| 13 | Dashboard loads in under 3 seconds |

---

## 10. Assumptions

1. All reviewers have a valid OJS user account.

2. OJS can store custom data fields for reviewers.

3. Review Group Members will respond to polls in a timely manner.

4. The plugin will integrate with existing OJS submission data.

5. Reviewers have access to view their own records.

---

## 11. Open Questions

| Number | Question | Who to Ask |
|--------|----------|------------|
| 1 | Should Review Group Members be able to see other members data | Client Eva |
| 2 | What threshold should trigger the high response rate low selection rate alert | Client Eva |
| 3 | Should Review Group Members be able to see their own response versus selection rate | Client Eva |

---

## 12. Future Considerations

1. This data will feed into Tool 2, the Editor Dashboard.

2. Future enhancements may include automated notifications for response versus selection rate gaps.
