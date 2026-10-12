# Reference Generator Test Plan

**Creator by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

**Task:** Writing reference generator tests

---

## 1. Task Scope

This test plan tells UX how to test the reference generator against the requirements. It covers the Generate Reference button, the year selector, the letter content, the preview, the download, and role-based access.

Each test has steps to follow and the expected result.

---

## 2. Before You Start

Make sure the Reviewers page is open in the Editor Monitoring Dashboard.

Log in as a Managing Editor or Quality Review Editor.

Pick a reviewer who has at least one completed review in the current year.

The test data should already be loaded.

---

## 3. Success Path Tests

These tests confirm the feature works when everything is done correctly.

### Test 1 Generate Reference button is visible

**User story:** As a Managing Editor I want to generate a reference letter from the reviewer page.

**Steps:**

1. Go to the Reviewers page.
2. Click on a reviewer's name to open their page.
3. Look at the top right of the page.

**Expected result:** A Generate Reference button is visible next to the Edit Labels button.

---

### Test 2 Clicking the button opens the generation screen

**Steps:**

1. Open a reviewer's page.
2. Click the Generate Reference button.

**Expected result:** A generation screen opens. It shows a year selector, a preview area, and a Generate PDF button.

---

### Test 3 Year selector defaults to the current year

**Steps:**

1. Open the generation screen for a reviewer.

**Expected result:** The year selector shows the current year by default.

---

### Test 4 Change the year

**User story:** As a Managing Editor I want to choose which year the reference covers.

**Steps:**

1. Open the generation screen.
2. Change the year selector to a different year.
3. Look at the preview.

**Expected result:** The preview updates to show data for the selected year.

---

### Test 5 Letter content matches the client's example

**User story:** As a Managing Editor I want the letter to follow the example from the client.

**Steps:**

1. Generate a reference for a reviewer.
2. Compare the letter with the client's example.

**Expected result:** The letter includes these sections in order:

- The date
- To whom it may concern
- Introduction with the reviewer's name and the year they joined
- The number of reviews completed in the chosen year
- The number of reviews led in the chosen year
- A bullet list of engagement
- A closing statement
- The editor names

---

### Test 6 Engagement bullets match the reviewer's records

**User story:** As a Managing Editor I want the letter to reflect what the reviewer actually did.

**Steps:**

1. Open a reviewer's page.
2. Note the contribution types and shaping feedback records for the chosen year.
3. Generate the reference.
4. Compare the bullets in the letter with the records.

**Expected result:** Every bullet in the letter matches a record for that reviewer. Bullets with no matching record are left out.

---

### Test 7 Bullets with no records are left out

**Steps:**

1. Find a reviewer who has not created a draft, but has uploaded notes.
2. Generate the reference.

**Expected result:** The letter includes "Preparing review notes" but not "Drafting feedback notes to authors".

---

### Test 8 Preview updates before download

**Steps:**

1. Open the generation screen.
2. Change the year.
3. Look at the preview area.

**Expected result:** The preview shows the current version of the letter for the selected year. It is not a placeholder.

---

### Test 9 Download the letter as PDF

**User story:** As a Managing Editor I want to download the reference as a PDF.

**Steps:**

1. Open the generation screen.
2. Click Generate PDF.

**Expected result:** A PDF file is downloaded. Opening it shows the same content as the preview.

---

### Test 10 Letter uses the reviewer's join year in the introduction

**Steps:**

1. Open a reviewer's page.
2. Note the Reviewer since value.
3. Generate the reference.

**Expected result:** The introduction uses the year from Reviewer since, not the chosen year.

---

## 4. Error Path Tests

These tests confirm the feature behaves correctly when something goes wrong.

### Test 11 Reviewer with no completed reviews

**Steps:**

1. Find a reviewer who has never completed a review.
2. Open their page.
3. Click Generate Reference.

**Expected result:** The button either does not appear, or clicking it shows a message saying no data is available for a reference.

---

### Test 12 Reviewer with no records in the chosen year

**Steps:**

1. Open a reviewer who has activity in one year but not in another.
2. Change the year selector to the year with no activity.
3. Look at the preview.

**Expected result:** The letter shows zero counts and no engagement bullets. The introduction still uses the correct join year.

---

### Test 13 Generating for a reviewer with all contribution types

**Steps:**

1. Find a reviewer with every contribution type recorded.
2. Generate the reference.

**Expected result:** The letter includes bullets for every recorded contribution. No bullets are missing.

---

### Test 14 Preview loads in a reasonable time

**Steps:**

1. Open a reviewer with lots of activity.
2. Click Generate Reference.
3. Time how long the preview takes to load.

**Expected result:** The preview loads in under 10 seconds.

---

## 5. Role-Based Access Tests

### Test 15 Only editors see the button

**Steps:**

1. Log out.
2. Log in as a Review Group Leader.
3. Open a reviewer's page.

**Expected result:** The Generate Reference button is not visible.

---

### Test 16 Reviewer cannot see the button

**Steps:**

1. Log out.
2. Log in as a Reviewer.
3. Open any page.

**Expected result:** The Generate Reference button is not visible anywhere.

---

### Test 17 Quality Review Editor can generate

**Steps:**

1. Log out.
2. Log in as a Quality Review Editor.
3. Open a reviewer's page.
4. Click Generate Reference.

**Expected result:** The button is visible and the letter generates the same way as for the Managing Editor.

---