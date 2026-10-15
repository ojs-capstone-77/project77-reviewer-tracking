# Filtering Test Plan

**Creator by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

**Task:** Writing further filtering tests

---

## 1. Task Scope

This test plan tells UX how to test the reviewer filtering feature against the requirements. It covers the label filters, the Available in an open poll filter, and the number range filters.

Each test has steps to follow and the expected result.

---

## 2. Before You Start

Make sure the Reviewers page is open in the Editor Monitoring Dashboard.

Log in as a Managing Editor or Quality Review Editor.

The test data should already be loaded.

---

## 3. Success Path Tests

These tests confirm the feature works when everything is done correctly.

### Test 1 Open the filter panel

**User story:** As a Managing Editor I want to filter reviewers by label values.

**Steps:**

1. Go to the Reviewers page.
2. Click the Filters button in the top right.
3. Look for the filter panel on the left.

**Expected result:** The filter panel opens. It shows label filters for experience level, methodology background, and expertise. It also shows the Available in an open poll input and the Activity range sliders.

---

### Test 2 Filter by experience level

**User story:** As a Managing Editor I want to filter reviewers by experience level so that I can find reviewers with the right background.

**Steps:**

1. Open the filter panel.
2. Click Novice under Experience level.
3. Look at the reviewer table.

**Expected result:** Only reviewers with experience level Novice appear in the table. The reviewer count at the top updates to show the new total.

---

### Test 3 Filter by methodology background

**User story:** As a Managing Editor I want to filter reviewers by methodology background so that I can match reviewers to different types of submissions.

**Steps:**

1. Clear any active filters.
2. Click Quantitative under Methodology background.
3. Look at the reviewer table.

**Expected result:** Only reviewers with methodology background Quantitative appear.

---

### Test 4 Filter by expertise

**User story:** As a Managing Editor I want to filter reviewers by expertise so that I can find people with specific skills.

**Steps:**

1. Clear any active filters.
2. Click Statistics under Expertise.
3. Look at the reviewer table.

**Expected result:** Only reviewers with expertise Statistics appear.

---

### Test 5 Clear a single filter

**User story:** As a Managing Editor I want to remove one filter without removing the others.

**Steps:**

1. Apply the Novice filter.
2. Look for the X icon next to the filter.
3. Click the X.
4. Look at the reviewer table.

**Expected result:** The Novice filter is removed. The table shows all reviewers again.

---

### Test 6 Set a value range on a column

**User story:** As a Managing Editor I want to set a range on any column.

**Steps:**

1. Clear any active filters.
2. Open the filter panel.
3. Find the Completed slider under Activity.
4. Click the + icon next to it.
5. Drag the left handle and the right handle to set a range.
6. Look at the reviewer table.

**Expected result:** Only reviewers whose Completed value falls within the range appear. The range shows as text under the slider, for example "2-5".

---

### Test 7 Find reviewers with no reviews done

**User story:** As a Managing Editor I want to see reviewers who have never done a review.

**Steps:**

1. Clear any active filters.
2. Open the filter panel.
3. Find the Completed slider.
4. Set both handles to 0.
5. Look at the reviewer table.

**Expected result:** Only reviewers with 0 completed reviews appear.

---

### Test 8 Filter by Available in an open poll

**User story:** As a Managing Editor I want to filter reviewers by whether they were available in a specific poll.

**Steps:**

1. Clear any active filters.
2. Open the filter panel.
3. Type a Submission ID into the Available in an open poll input.
4. Look at the reviewer table.

**Expected result:** Only reviewers who responded as available in the poll for that submission appear.

---

### Test 9 Combine a label filter with a range filter

**User story:** As a Managing Editor I want to combine filters to find specific groups of reviewers.

**Steps:**

1. Clear any active filters.
2. Click Novice under Experience level.
3. Set the Completed slider to 0-0.
4. Look at the reviewer table.

**Expected result:** Only reviewers who are Novice and have 0 completed reviews appear.

---

### Test 10 Sorting works with filters applied

**User story:** As a Managing Editor I want to sort the filtered list.

**Steps:**

1. Apply the Novice filter.
2. Click the Completed column header to sort.
3. Look at the order of the reviewers.

**Expected result:** The filtered list is sorted by Completed in ascending order. Clicking again sorts in descending order.

---

## 4. Error Path Tests

These tests confirm the feature behaves correctly when something goes wrong.

### Test 11 Enter an invalid Submission ID

**Steps:**

1. Open the filter panel.
2. Type letters into the Submission ID input.
3. Look at the reviewer table.

**Expected result:** The input either rejects the letters or shows no results. The page does not crash.

---

### Test 12 Enter a Submission ID that does not exist

**Steps:**

1. Open the filter panel.
2. Type a Submission ID that is not in the system.
3. Look at the reviewer table.

**Expected result:** The table shows no reviewers. A message appears saying no reviewers match the filter.

---

### Test 13 Set an impossible range

**Steps:**

1. Open the filter panel.
2. Set the Completed slider so the minimum is higher than the maximum.
3. Look at the reviewer table.

**Expected result:** The slider prevents this. Either the handles swap, or the range stays valid.

---

### Test 14 Apply a filter that matches no reviewers

**Steps:**

1. Open the filter panel.
2. Apply a combination of filters that no reviewer matches.
3. Look at the reviewer table.

**Expected result:** The table shows no reviewers. A message appears saying no reviewers match the filters. The clear option is still available.

---

## 5. Role-Based Access Tests

### Test 15 Review Group Leader cannot use filters

**Steps:**

1. Log out.
2. Log in as a Review Group Leader.
3. Go to the Group Review page.

**Expected result:** The Reviewers page and filter panel are not accessible.

---

### Test 16 Quality Review Editor can use filters

**Steps:**

1. Log out.
2. Log in as a Quality Review Editor.
3. Go to the Reviewers page.
4. Open the filter panel.

**Expected result:** The filter panel opens and all filters work the same way as for the Managing Editor.

---