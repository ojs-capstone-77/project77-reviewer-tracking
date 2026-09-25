# Test Plan Reviewer Monitoring

**Creator by:** Jahan Haidari BA

**Team:** 77

**Sprint:** 2

---

## 1. Our Test Plan
This test plan covers the reviewer monnitoring dashboard. This dashboard shows editors reviewer poll response data, selection data, and review load and highlights reviewers who are overlooked or overburdened.

Testing will be done against the Figma prototype V3 reviewer participation recording using the flows shown in the design which are:
**Editor monitoring**
**Participation form settings**
**Reviewer labels settings**

## 2. Navigation Tests
| Test | Steps | Expected Result |
|------|-------|-----------------|
| Acess editor monitoring | Open journal, navigate to Editor monitoring flow | Dashboard loads correctly |
| Access participation form settings | Navigate to workflow -> Review -> Participation Form | Form items displays **Review meetng**, **feedback response**, **other**, **General** |
| Access reviewer labels settings | Navigate to workflow -> Review -> Reviewer labels | Labels displays **experience level**, **methodology background**, **expertise** |
| Access group review tab | **Open submission #114**, **click group review tab** | Group review options display with create group review poll, my group review polls, reviewer participation |

## 3. Dashboard Filter Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| All reviewers filter | Open dashboard, select all reviewers | All reviewers display with load, contributions and actions |
| Overloaded filter | Select **Overloaded** | Only reviewers with load above threshold display |
| Unresponsive filter | Select **Unresponsive** | Only reviewers with no repsonse above threshold display |
| Overlooked filter | Select **Overlooked** | Only reviewers with high response rate but low selection rate display |
| Search by name | Type a reviewer name is search | Dashboard filters to matching reviewers |

## 4. Data Accuracy Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Response rate displayed | View a reviewer with known poll history | Reponse rate matches expected percentage |
| Selection rate displayed | View a rviewer with known selection history | Selection rate matches expected percentage |
| Review load displayed | View a reviewer with active reviews | Review load matches number of active reviews |
| Selection history displayed | Open individual reviewer detail view | Selection history shows correct submissions |
| Contribution history displayed | Open individual reviewer detail view | Contribution types and attendanc match recorded data |
| Reviewer labels display | Open individual reviewer detail view | **Experiene level**, **methodology background** and **expertise** display correctly |

## 5. Participation Form Settings Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Form items display | Navigate to participation form settings | **Rview meeting**, **feedback response**, **Other** and **General** sections display |
| Meeting attendance editable | Click **Edit** on meeting attendance | Attendance options are editable |
| Create new item | Click Create **New Item** | New form item can be added |
| Order form items | Click **Order** | Form items can be reordered |

## 6. Reviewer Labels Settings Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Labels display | Navigate to Reviewer Labels settings | **Experience level**, **Methodology background** and **Expertise** display |
| Labels editable | Click **Edit** on a label | Label can be edited |
| Create new label | Click Create **New Item** | New label can be added |
| Order labels | Click **Order** | Labels can be reordered |

## 7. Edge Case Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Reviewer with no poll responses | View a reviewer who has never responded | Dashboard shows zero or appropriate empty state |
| Reviewer with no selection history | View a reviewer who has never been selected | Selection rate shows zero or appropriate empty state |
| Reviewer with no reviews completed | View a new reviewer | Review load shows zero |
| Reviewer with no contribution records | View a reviewer with no participation data | Contribution section shows empty state |
| Dashboard with no reviewers | Open dashboard with empty reviewer list | Empty state displays correctly |
| No group review poll created | Open submission Group Review tab with no poll | Message displays "No group review poll has been created for the current external review round." |

## 8. Role Based Access Tests

| Test | Role | Expected Result |
|------|------|-----------------|
| Managing Editor access | Managing Editor | Can view all reviewers and all data |
| Quality Review Editor access | Quality Review Editor | Can view all reviewers and all data |
| Review Group Leader access | Review Group Leader | Can view limited data only for reviewers who responded with availability |
| Reviewer access | Reviewer | Can view only their own records |
| Unauthorized access | Any other role | Cannot access dashboard |

## 9. Performance Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Dashboard load time | Open dashboard | Loads in under 3 seconds |
| Filter response time | Apply a filter | Results update in under 3 seconds |
| Individual reviewer load time | Open reviewer detail view | Loads in under 3 seconds |

