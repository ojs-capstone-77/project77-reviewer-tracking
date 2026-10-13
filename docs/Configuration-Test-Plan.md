# Configuration Test Plan Reviewer Participation Form

**Creator by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

**Task:** Writing tests for reviewer participation form and label configuration

---

## 1. Task Scope

This test plan tells UX how to test the configuration feature for the participation form and reviewer labels. It covers what editors can configure, what stays locked, and what happens to existing data when settings change.

Each test has steps to follow and the expected result.

---

## 2. Before You Start

Log in as a Managing Editor or Quality Review Editor.

Go to the journal settings for the Group Review plugin.

The test data should already be loaded.

---

## 3. Success Path Tests

These tests confirm the feature works when everything is done correctly.

### Test 1 Open the configuration settings

**User story:** As a Managing Editor I want to configure the participation form and labels from journal settings.

**Steps:**

1. Go to Workflow Settings.
2. Click the Review tab.
3. Look for the participation form and label configuration options.

**Expected result:** The configuration options are visible. They include settings for form fields, contribution types, attendance options, shaping feedback options, and reviewer labels.

---

### Test 2 Mark a form field as optional

**User story:** As a Managing Editor I want to configure which form fields are required or optional.

**Steps:**

1. Open the participation form configuration.
2. Find a field that is currently required, for example Attendance.
3. Change it to optional.
4. Save the settings.
5. Open a submission's Group Review tab as a Review Group Leader.

**Expected result:** The field is shown as optional in the form. The RGL can save the form without filling it in.

---

### Test 3 Mark a form field as required

**Steps:**

1. Open the participation form configuration.
2. Find a field that is currently optional.
3. Change it to required.
4. Save the settings.
5. Open a submission's Group Review tab as an RGL.

**Expected result:** The field is shown as required. The RGL cannot save the form without filling it in.

---

### Test 4 Add a new contribution type

**User story:** As a Managing Editor I want to manage the list of contribution types.

**Steps:**

1. Open the contribution type configuration.
2. Click Add.
3. Enter a new contribution type, for example "Peer feedback".
4. Save the settings.
5. Open a submission's Group Review tab as an RGL.

**Expected result:** The new contribution type appears in the form for the RGL to select.

---

### Test 5 Remove a contribution type

**Steps:**

1. Open the contribution type configuration.
2. Remove an existing type, for example "Analysis".
3. Save the settings.
4. Open a submission's Group Review tab as an RGL.

**Expected result:** The removed contribution type does not appear in the form.

---

### Test 6 Add a new attendance option

**User story:** As a Managing Editor I want to manage the list of attendance options.

**Steps:**

1. Open the attendance option configuration.
2. Click Add.
3. Enter a new option.
4. Save the settings.
5. Open a submission's Group Review tab as an RGL.

**Expected result:** The new attendance option appears in the dropdown.

---

### Test 7 Add a new shaping feedback option

**User story:** As a Managing Editor I want to manage the list of shaping feedback options.

**Steps:**

1. Open the shaping feedback option configuration.
2. Click Add.
3. Enter a new option.
4. Save the settings.
5. Open a submission's Group Review tab as an RGL.

**Expected result:** The new shaping feedback option appears in the form as a checkbox.

---

### Test 8 Add a new label type

**User story:** As a Managing Editor I want to add, rename, and remove label types.

**Steps:**

1. Open the reviewer label configuration.
2. Click Add label type.
3. Enter a name, for example "Language".
4. Choose single or multiple choice.
5. Save the settings.
6. Open a reviewer's page.

**Expected result:** The new label type appears on the reviewer page and can be assigned.

---

### Test 9 Rename a label type

**Steps:**

1. Open the reviewer label configuration.
2. Rename "Expertise" to "Subject area".
3. Save the settings.
4. Open a reviewer's page.

**Expected result:** The label type is now called "Subject area". Existing values are still attached to the reviewers who had them.

---

### Test 10 Remove a label type

**Steps:**

1. Open the reviewer label configuration.
2. Remove a label type, for example "Methodology background".
3. Save the settings.
4. Open the Reviewers page and the Overview page.

**Expected result:** The removed label type no longer appears in the settings. It no longer appears as a column on the Reviewers page. It no longer appears as a count on the Overview page. Reviewers who already had a value keep it in the database but it is not shown.

---

### Test 11 Set a label type as single choice

**Steps:**

1. Open the reviewer label configuration.
2. Create a label type called "Availability" and set it to single choice.
3. Save the settings.
4. Open a reviewer's page and try to assign two values.

**Expected result:** Only one value can be selected. The page prevents a second choice.

---

### Test 12 Set a label type as multiple choice

**Steps:**

1. Open the reviewer label configuration.
2. Create a label type called "Skills" and set it to multiple choice.
3. Save the settings.
4. Open a reviewer's page and assign two values.

**Expected result:** Both values are saved. The reviewer shows both values.

---

### Test 13 Other field is always present

**Steps:**

1. Open the participation form configuration.
2. Look for the Other field per reviewer.

**Expected result:** The Other field cannot be removed or hidden. It is not configurable.

---

### Test 14 General comments field is always present

**Steps:**

1. Open the participation form configuration.
2. Look for the general comments field for the editors.

**Expected result:** The general comments field cannot be removed or hidden. It is not configurable.

---

### Test 15 Configuration changes do not affect existing forms

**User story:** As a Managing Editor I want historical records to keep their original values.

**Steps:**

1. Open an existing submission that already has a saved participation form.
2. Note the contribution types and attendance options recorded.
3. Remove one of those options in the settings.
4. Reopen the saved form and the reviewer page.

**Expected result:** The historical record still shows the original value. Removing the option does not change what was already saved.

---

### Test 16 Dashboard label counts update dynamically

**Steps:**

1. Open the Overview page and note the label counts.
2. Add a new label type in the settings.
3. Return to the Overview page.

**Expected result:** The new label type appears in the label counts section. Counts show zero until reviewers are assigned values.

---

### Test 17 Label changes are tracked in history

**User story:** As a Managing Editor I want to see the history of label changes.

**Steps:**

1. Open a reviewer's page.
2. Change their experience level from Novice to Intermediate.
3. Look at the label history on the reviewer page.

**Expected result:** The change is recorded with the date, the person who made the change, and a summary of what changed.

---

## 4. Error Path Tests

These tests confirm the feature behaves correctly when something goes wrong.

### Test 18 Try to save an empty label type name

**Steps:**

1. Open the label configuration.
2. Click Add label type.
3. Leave the name blank.
4. Click Save.

**Expected result:** The save is rejected. An error message appears saying the name is required.

---

### Test 19 Try to save an empty contribution type

**Steps:**

1. Open the contribution type configuration.
2. Click Add.
3. Leave the name blank.
4. Click Save.

**Expected result:** The save is rejected. An error message appears saying the name is required.

---

### Test 20 Try to add a duplicate label type

**Steps:**

1. Open the label configuration.
2. Click Add label type.
3. Enter a name that already exists, for example "Expertise".
4. Click Save.

**Expected result:** The save is rejected. An error message appears saying the name already exists.

---

### Test 21 Try to remove the last required field

**Steps:**

1. Open the participation form configuration.
2. Try to remove every contribution type.
3. Click Save.

**Expected result:** The save is rejected. An error message appears saying at least one option must remain.

---

### Test 22 Remove a label type that reviewers already have values for

**Steps:**

1. Open the label configuration.
2. Remove a label type that has been assigned to at least one reviewer.
3. Confirm the removal.

**Expected result:** The label type is removed from the settings. Existing values stay in the database. A confirmation message warns the editor that existing reviewers will keep their values but the label will no longer show.

---

## 5. Role-Based Access Tests

### Test 23 Only editors can configure

**Steps:**

1. Log out.
2. Log in as a Review Group Leader.
3. Go to Workflow Settings.

**Expected result:** The configuration options are not accessible. Only Managing Editors and Quality Review Editors can see them.

---

### Test 24 Reviewer cannot configure

**Steps:**

1. Log out.
2. Log in as a Reviewer.
3. Go to Workflow Settings.

**Expected result:** The configuration options are not accessible.

---

### Test 25 Quality Review Editor can configure

**Steps:**

1. Log out.
2. Log in as a Quality Review Editor.
3. Go to Workflow Settings.

**Expected result:** The configuration options are visible and work the same way as for the Managing Editor.

---