# Reviewer Participation Visibility Requirements

**Prepared by:** Jahan Haidari (BA)
**Team:** 77

---

## 1. General Information

This document defines what reviewer participation data needs to be visible to different roles in the ASRHE journal system. 
The data comes from two sources and is considered high priority by our client Eva:

- **Feature 1:** Poll/selection tool (RGL creates polls, RGMs respond, RGL selects reviewers)
- **Feature 2:** Participation recording tool (RGL records attendance, contributions, strengths, development opportunities)

The client requires that **Feature 2 replaces the current Microsoft Form** and is integrated directly into the OJS plugin.

### The Core Problem

Currently, Review Group Leaders (RGLs) can only see a list of RGM names who responded to a poll. They have **no context** about that RGM's participation history things like how often they respond, how often they're selected, or whether they actually participate when selected.

**Tool 2 (Editor Dashboard) solves this by revealing patterns in reviewer participation that are currently invisible.**

---

## 2. Data Fields to Show

### Feature 1: Poll/Selection Data

| Data Field | Description | Purpose |
|------------|-------------|---------|
| Poll responses | Whether RGM responded to the poll (Yes/No) | Track engagement |
| Response rate | % of polls responded to over time | Identify engaged reviewers |
| Selection rate | % of polls where RGM was selected | Identify repeatedly overlooked reviewers |
| Selection history | Which submissions the RGM was selected for | Track workload distribution |
| Response vs. selection gap | Responded but never/rarely selected | Reveal potential bias or oversight |
| Review load | Number of active reviews per RGM | Identify overburdened reviewers |

### Feature 2: Participation Recording Data (Replaces Microsoft Form)

| Data Field | Description | Source |
|------------|-------------|--------|
| Article number and first author | Submission identification | OJS submission |
| Review phase | Which review round (first, second, etc.) | OJS submission |
| RGL name | Name of the Review Group Leader (auto-filled) | OJS user |
| Reviewer name | Name of the reviewer being evaluated | OJS user |
| Attendance | Attended / Did not attend (apology) / Did not attend (no apology) / N.A. / Other | Feature 2 (plugin) |
| Participation frequency | How often the RGM attends vs. is selected | Feature 2 (plugin) |
| Comments on contributions | Strengths and development opportunities (free text) | Feature 2 (plugin) |
| Shaping the feedback response | Uploaded notes / Commented on draft / Created the draft | Feature 2 (plugin) |
| Comments on shaping feedback | Additional notes on contributions | Feature 2 (plugin) |
| Contribution types | Discussion, Writing, Analysis, Editing, Other | Feature 2 (plugin) |
| Strengths demonstrated | Key strengths recorded | Feature 2 (plugin) |
| Development opportunities | Areas for improvement | Feature 2 (plugin) |

---

## 3. Who Needs to See It

| Role | What They See | Why |
|------|---------------|-----|
| **Review Group Leader** | All data (Feature 1 + Feature 2) | To create polls, select RGMs, and record participation |
| **Managing Editor** | All data (read-only) | To oversee the process and assess reviewer workloads |
| **Quality Review Editor** | All data (read-only) | To monitor reviewer performance and identify development needs |
| **Review Group Member** | Their own poll responses, their own participation records | To respond to polls and track personal contributions |
| **Reviewer** | Their own participation records | To track personal contributions and development |

---

## 4. Decisions the Data Supports

| Decision | Data That Helps |
|----------|-----------------|
| Who is being overlooked? | Response rate vs. selection rate gap |
| Who is overburdened? | Selection history, review load |
| Who is engaged but never selected? | Poll response rate + selection rate |
| Who actually contributes? | Attendance, participation frequency, contribution types |
| Who is ready for more responsibility? | Strengths, shaping feedback responses |
| Who needs support? | Development opportunities |
| Who to invite as a reviewer? | Poll responses, availability, selection history, review load |
| Who contributed to the review report? | Shaping the feedback response |

---

## 5. Problem It Solves

| Problem | Solution |
|---------|----------|
| RGMs who are always available but never selected | Dashboard shows response rate vs. selection rate — editors can see the gap and correct it |
| Overburdened reviewers | Selection history and review load are visible — editors can balance workload |
| Engagement is invisible | Poll response rate and participation frequency are displayed |
| Bias in reviewer selection | Data reveals patterns — editors can make informed decisions |
| No visibility into who actually contributes | Attendance, contribution types, and shaping feedback are recorded |
| External Microsoft Form is disconnected from OJS | Feature 2 integrates the form directly into OJS |
| Manual data entry and coordination | Feature 1 automates poll/selection process |
| Reviewer data is scattered | All data centralized in OJS |

---

## 6. When and Where in OJS

| Context | When/Where |
|---------|------------|
| **When selecting reviewers** | Feature 1 (poll/selection tool) — appears during review setup |
| **When recording participation** | Feature 2 — appears after the group review meeting |
| **When viewing reviewer data** | Dashboard — accessible from the journal dashboard |
| **When generating references** | Data feeds into Tool 3 |

---

## 7. User Stories

### 7.1 Review Group Leader (Feature 1)

> As a **Review Group Leader**, I want to create a poll to invite Review Group Members, so that I can select reviewers for the group review.

> As a **Review Group Leader**, I want to see poll responses from Review Group Members, so that I can select reviewers based on their availability.

> As a **Review Group Leader**, I want to see an RGM's poll response history, so that I can identify who is consistently engaged.

> As a **Review Group Leader**, I want to see an RGM's selection history, so that I can distribute workload fairly.

> As a **Review Group Leader**, I want to see who responds to polls but is never selected, so that I can ensure they get opportunities.

### 7.2 Review Group Leader (Feature 2)

> As a **Review Group Leader**, I want to record reviewer participation after the meeting, so that I can capture attendance, contributions, and strengths.

> As a **Review Group Leader**, I want to record shaping feedback responses (uploaded notes, commented on draft, created draft), so that I can track who contributed to the review report.

> As a **Review Group Leader**, I want to complete the form within OJS, so that I don't have to use an external Microsoft Form.

### 7.3 Managing Editor

> As a **Managing Editor**, I want to view all reviewer participation data on a dashboard, so that I can assess reviewer workloads and make informed selection decisions.

> As a **Managing Editor**, I want to view poll responses and selection history, so that I can oversee the review process.

> As a **Managing Editor**, I want to see participation patterns across all RGMs, so that I can identify bias or unfair workload distribution.

> As a **Managing Editor**, I want to see who is overburdened, so that I can balance the workload.

### 7.4 Quality Review Editor

> As a **Quality Review Editor**, I want to view reviewer participation data, so that I can identify development needs and support reviewers.

> As a **Quality Review Editor**, I want to see who is engaged but overlooked, so that I can recommend them for future reviews.

### 7.5 Reviewer / RGM

> As a **Reviewer**, I want to view my own participation records, so that I can track my contributions and development.

> As a **Review Group Member**, I want to respond to polls, so that I can indicate my availability.

> As a **Review Group Member**, I want to see my own response and selection history, so that I can understand my engagement patterns.

---

## 8. Roles and Permissions

| Role | Can View | Can Edit | Can Create |
|------|----------|----------|------------|
| **Review Group Leader** | All data | All data (their group) | Polls, participation records |
| **Managing Editor** | All data | Read-only | No |
| **Quality Review Editor** | All data | Read-only | No |
| **Review Group Member** | Their own data | Their own responses | Poll responses |
| **Reviewer** | Their own records | No | No |

---

## 9. Acceptance Criteria

### Feature 1: Poll/Selection Data

|  | Acceptance Criteria |
|--|---------------------|
| 1 | RGL can create a poll to invite RGMs |
| 2 | RGMs can respond to the poll (availability, willingness) |
| 3 | RGL can select RGMs based on poll responses |
| 4 | RGL can view each RGM's poll response history |
| 5 | RGL can view each RGM's selection history (which submissions they were selected for) |
| 6 | RGL can view each RGM's response rate (% of polls responded to) |
| 7 | RGL can view each RGM's selection rate (% of polls where they were selected) |
| 8 | Dashboard highlights RGMs with high response rate but low selection rate |
| 9 | Dashboard highlights RGMs with high selection rate (overburdened) |
| 10 | Selection history is stored and visible in the dashboard |

### Feature 2: Participation Recording

|  | Acceptance Criteria |
|--|---------------------|
| 11 | RGL can record attendance (Attended, Did not attend with apology, Did not attend without apology, N.A., Other) |
| 12 | RGL can record contribution types (Discussion, Writing, Analysis, Editing, Other) |
| 13 | RGL can record strengths (free text) |
| 14 | RGL can record development opportunities (free text) |
| 15 | RGL can record shaping feedback responses (Uploaded notes, Commented on draft, Created the draft) |
| 16 | Participation frequency is calculated from attendance records |
| 17 | All data is stored in OJS and linked to the reviewer's profile |
| 18 | The Microsoft Form is replaced by the in-plugin form |

### Dashboard Visibility

|  | Acceptance Criteria |
|--|---------------------|
| 19 | Dashboard displays poll response rate and selection rate for each RGM |
| 20 | Dashboard displays participation frequency (attendance records) |
| 21 | Dashboard highlights RGMs with response rate > 80% but selection rate < 20% |
| 22 | Dashboard displays selection history for each RGM |
| 23 | Dashboard is accessible to Managing Editors and Quality Review Editors |
| 24 | Dashboard filters by reviewer name, contribution type, and date range |
| 25 | Dashboard loads in under 3 seconds |

---

## 10. Assumptions

|  | Assumption |
|--|------------|
| 1 | All reviewers have a valid OJS user account |
| 2 | OJS can store custom data fields for reviewers |
| 3 | RGMs will respond to polls in a timely manner |
| 4 | RGLs will record participation data immediately after meetings |
| 5 | The plugin will integrate with existing OJS submission data |
| 6 | Historical data (from the Microsoft Form) will not be migrated |
| 7 | Reviewers have access to view their own records |
| 8 | The in-plugin form will replace the external Microsoft Form |

---

## 11. Open Questions

|  | Question | Who to Ask |
|--|----------|------------|
| 1 | Should reviewers be able to see other reviewers' data? | Client |
| 2 | What specific contribution types are most important to track? | Client |
| 3 | How should strengths and development opportunities be formatted? | Client |
| 4 | Should the dashboard allow exporting data to CSV? | Client |
| 5 | Should annual references be generated automatically or on-demand? | Client |
| 6 | How should shaping feedback responses be displayed in the dashboard? | Client |
| 7 | What happens to existing Microsoft Form data? | Client |
| 8 | What threshold should trigger the "high response rate, low selection rate" alert? | Client |
| 9 | Should RGMs be able to see their own response vs. selection rate? | Client |

---

## 12. Future Considerations

|  | Consideration |
|--|---------------|
| 1 | Feature 1 and Feature 2 data will feed into Tool 3 (reference generator) |
| 2 | The dashboard will enable editor monitoring and workload assessment |
| 3 | Future enhancements may include advanced analytics and reporting |
| 4 | The in-plugin form will replace the external Microsoft Form |
| 5 | Response rate vs. selection rate alerts could be expanded to include automated notifications |

---