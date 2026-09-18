# Test Plan Participation Recording 
**Team** 77
**Sprint** 2

## 1. About the plan
This test plan covers the participation recording feature. This feature replaces the Microsoft form and allows review group leaders to record reviewer participation during group review meetings.

## 2. Happy path tests
These tests cofnirm the feature works as expected when everything goes right.

| Test | Steps | Espected result |
|------|-------|-----------------|
| Open form | RGL opens a submission in review stage, clicks group review tab | Form loads with all fields visible }
| Record attendance | RGL selects a reviewer and chooses "attended" | Attendance is recorded |
| Record constributions | RGL checks contribution types (discussion, writing) | Contribution types are saved |
| Record strengths | RGL enters clear written feedback | Strengths are saved |
| Record development | RGL enters improve turnaround time | Development opportunities are saved |
| Record shaping feedback | RGL checks created the draft | Shaping feedback is saved |
| Save form | RGL clicks save | Confirmation message displays, data is saved to OJS |
| Load saved data | RGL reopens the form | Previously saved data loads correctly |

## 3. Error stste tests
These tests confirm the feature handles errors correctly.
| Test | Steps | Expected result |
|------|-------|-----------------|
| Missing required field | RGL leaves a required field blank and clicks save | Error message displays, form does not save |
| Invalid input | RGL eneters invalid data (if applicable) | Error message displays |
| Save failure | Simulate a save failure | Error message displays, data is not lost |
| Unauthorised access | Someone other then RGL tries ro access the form | Access denied |

## 4. Edge case tests
These tests cover unusual or boundary scenarios.
| Test | Stage | Expected result |
|------|-------|-----------------|
| Second round review with no meeting | RGL opens a second round submission with no scheduled meeting | From allows N/A attendance options |
| Maximum reviewers | RGL records participation for 5 reviewers| Form handles multiple reviewer correctly |
| No reviewers assigned | RGL opens a submission with no reviewer | Form shows empty state or approperiate message |
| Draft save | RGL saves draft and returns later | Drft data is preserved |

## 5. Role based access tests
These tests confirm the right people can do the right things.
| Test | Role | Expected result |
| RGL records participation | Review group leader | Can access form and record data |
| managing editor view records | Managing editor | Can view all records, cannot edit |
| Quality review editor views records | Quality review editor | Can view all records, cannot edit |
| Reviewer views own records | Reviewer | Can view only their own records |
| unauthorised user blocked | Any other role | Cannot access the form |

## 6. Test data
Use the following test data for testing.
| field | Test value |
|-------|------------|
| Submission | Test submission #114 |
| Reviewer | J. Alvarez |
| Attendance | Attended |
| Contribution types | Discussion, writing |
| Strengths | Clear written feedback |
| Development opportunites | Improve turnaround time |
| Shaping feedback | Created the draft |

