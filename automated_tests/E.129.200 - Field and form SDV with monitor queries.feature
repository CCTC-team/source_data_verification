Feature: E.129.200 - The system shall allow monitors to mark fields as requiring SDV, verify individual fields, raise and resolve monitor queries with data entry users, and mark forms as SDV complete, re-opening verification when verified data changes.

  As a monitor
  I want to verify fields against source and mark forms as SDV complete
  So that the sponsor has an auditable record of source data verification

  Scenario: Enable external Module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Source Data Verification - v1.0.0"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    And I click on the button labeled "Enable" in the row labeled "Source Data Verification"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Source Data Verification - v1.0.0"

  Scenario: Set up the project
    Given I create a new project named "E.129.200" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/ProjectTypes/RepeatingEvents_DoubleArm_withDAGs.xml", and clicking the "Create Project" button
    And I click on the second link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Source Data Verification - v1.0.0"
    Then I should see "Source Data Verification - v1.0.0"

    Given I click on the link labeled "User Rights"
    When I enter "Test_User1" into the field with the placeholder text of "Assign new user to role"
    And I click on the button labeled "Assign to role"
    And I select "DataEntryPI" on the dropdown field labeled "Select Role" on the role selector dropdown
    When I click on the button labeled "Assign"
    Then I should see "Test User1" within the "DataEntryPI" row of the column labeled "Username" of the User Rights table
    When I enter "Test_User2" into the field with the placeholder text of "Assign new user to role"
    And I click on the button labeled "Assign to role"
    And I select "DataManager" on the dropdown field labeled "Select Role" on the role selector dropdown
    When I click on the button labeled "Assign"
    Then I should see "Test User2" within the "DataManager" row of the column labeled "Username" of the User Rights table
    When I enter "Test_User4" into the field with the placeholder text of "Assign new user to role"
    And I click on the button labeled "Assign to role"
    And I select "Monitor" on the dropdown field labeled "Select Role" on the role selector dropdown
    When I click on the button labeled "Assign"
    Then I should see "Test User4" within the "Monitor" row of the column labeled "Username" of the User Rights table

    Given I click on the link labeled "Data Import Tool"
    And I upload a "csv" format file located at "fixtures/import_files/RepeatingEvents_DoubleArm_withDAGs.csv", by clicking the button near "Select your CSV data file" to browse for the file, and clicking the button labeled "Upload File" to upload the file
    And I click on the button labeled "Import Data"
    Then I should see "Import Successful!"

    Given I click on the second link labeled "Manage"
    And I click on the button labeled "Configure"
    And I select "Monitor" on the dropdown field labeled "1. Monitor role(s)"
    And I select "DataEntryPI" on the dropdown field labeled "1. Data entry role(s)"
    And I select "DataManager" on the dropdown field labeled "1. Data manager role(s)"
    And I enter "@ENDPOINT-\w+" into the input field labeled "Regex matching the action tags of fields that require SDV"
    And I enter "@NOTMONITORED" into the input field labeled "Action tag that excludes a field from SDV entirely"
    And I select "the changed field had been verified (recommended)" on the dropdown field labeled "A form marked 'SDV complete' is set back"
    And I check the checkbox labeled "Show open query text next to the field label for data entry users"
    When I click on the button labeled "Save"
    Then I should see "Source Data Verification - v1.0.0"
    And I logout

  Scenario: E.129.200 - Monitor verifies fields, queries the site and completes SDV
    Given I login to REDCap with the user "Test_User4"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.200"
    And I click on the link labeled "Record Status Dashboard"
    And I click on the link labeled "1-1"
    When I click the bubble to select a record for the "Text Validation" longitudinal instrument on event "Event 2"
    Then I should see "Source Data Verification"
    And I should see the SDV form status "SDV pending"
    And I should see "Required fields verified: 0 / 2"
    And I should see the SDV badge "SDV required" for the field "ptname_v2"
    And I should see the SDV badge "SDV required" for the field "email_v2"
    And I should see the SDV badge "Excluded from SDV" for the field "text_validation_crfver"

    # VERIFY - the form cannot be completed until the required fields are verified
    When I click on the button labeled "Mark SDV complete"
    Then I should see the SDV message "Not verified: ptname_v2, email_v2"

    # VERIFY - monitors mark an extra field as requiring SDV from the form
    When I select the SDV requirement "required" for the field "text_validation_monstat"
    Then I should see the SDV badge "SDV required" for the field "text_validation_monstat"
    And I should see "Required fields verified: 0 / 3"
    When I select the SDV requirement "optional" for the field "text_validation_monstat"
    Then I should see "Required fields verified: 0 / 2"

    # VERIFY - field verification
    When I click on the SDV button labeled "Verify" for the field "ptname_v2"
    Then I should see the SDV badge "SDV verified" for the field "ptname_v2"
    And I should see the SDV form status "SDV in progress"
    And I should see "Required fields verified: 1 / 2"

    # VERIFY - raise a monitor query
    When I click on the SDV button labeled "Raise query" for the field "email_v2"
    And I enter "Email missing - please check the source document" as the SDV query for the field "email_v2"
    And I click on the SDV button labeled "Raise query" for the field "email_v2"
    Then I should see the SDV message "Query raised."
    And I should see the SDV badge "Query open" for the field "email_v2"
    And I should see "Active queries: 1"
    When I click on the button labeled "Mark SDV complete"
    Then I should see the SDV message "Not verified: email_v2"
    And I logout

    # VERIFY - data entry user responds to the query
    Given I login to REDCap with the user "Test_User1"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.200"
    And I click on the link labeled "Record Status Dashboard"
    And I click on the link labeled "1-1"
    When I click the bubble to select a record for the "Text Validation" longitudinal instrument on event "Event 2"
    Then I should see "Email missing - please check the source document"
    And I should NOT see a button labeled "Mark SDV complete"
    When I clear field and enter "subject@example.org" into the input field labeled "Email"
    And I select the submit option labeled "Save & Stay" on the Data Collection Instrument
    Then I should see the SDV badge "Query open" for the field "email_v2"
    When I select the SDV response "Value updated as per source" for the query on the field "email_v2"
    And I enter "Entered from the consent form" as the SDV response comment for the field "email_v2"
    And I click on the SDV query button labeled "Send response" for the field "email_v2"
    Then I should see the SDV message "Response sent."
    And I should see the SDV badge "Query responded" for the field "email_v2"
    And I logout

    # VERIFY - data manager can view but not respond or change the plan
    Given I login to REDCap with the user "Test_User2"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.200"
    And I click on the link labeled "SDV Plan"
    Then I should see "Only monitors can change the SDV plan."
    And I logout

    # VERIFY - monitor accepts the response, verifies the field and completes SDV
    Given I login to REDCap with the user "Test_User4"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.200"
    And I click on the link labeled "Record Status Dashboard"
    And I click on the link labeled "1-1"
    When I click the bubble to select a record for the "Text Validation" longitudinal instrument on event "Event 2"
    Then I should see "Value updated as per source: Entered from the consent form"
    When I click on the SDV query button labeled "Accept" for the field "email_v2"
    Then I should see the SDV message "Response accepted and query closed."
    And I should see the SDV badge "SDV verified" for the field "email_v2"
    And I should see "Required fields verified: 2 / 2"
    When I click on the button labeled "Mark SDV complete"
    Then I should see the SDV form status "SDV complete"
    And I should see "Marked SDV complete by test_user4"
    And I should NOT see the SDV badge "Verify" for the field "ptname_v2"

    # VERIFY - history
    When I click on the button labeled "Show history"
    Then I should see "Form marked SDV complete"
    And I should see "Query accepted"
    And I should see "Field verified"
    And I logout

    # VERIFY - changing a verified value re-opens SDV (trigger: the changed field had been verified)
    Given I login to REDCap with the user "Test_User1"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.200"
    And I click on the link labeled "Record Status Dashboard"
    And I click on the link labeled "1-1"
    When I click the bubble to select a record for the "Text Validation" longitudinal instrument on event "Event 2"
    When I clear field and enter "corrected@example.org" into the input field labeled "Email"
    And I select the submit option labeled "Save & Stay" on the Data Collection Instrument
    Then I should see the SDV form status "Re-verification required"
    And I should see the SDV badge "Changed since SDV" for the field "email_v2"
    And I should see the SDV badge "SDV verified" for the field "ptname_v2"
    And I logout

    # VERIFY - not required and reopen require a reason
    Given I login to REDCap with the user "Test_User4"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.200"
    And I click on the link labeled "Record Status Dashboard"
    And I click on the link labeled "1-1"
    When I click the bubble to select a record for the "Text Validation" longitudinal instrument on event "Event 2"
    Then I should see the SDV form status "Re-verification required"
    When I click on the button labeled "Mark SDV not required"
    And I click on the button labeled "Confirm not required"
    Then I should see the SDV message "Please enter a reason."
    When I enter "Reduced SDV per monitoring plan v2" as the SDV reason
    And I click on the button labeled "Confirm not required"
    Then I should see the SDV form status "SDV not required"
    When I click on the button labeled "Reopen SDV"
    And I enter "Sponsor requested full SDV" as the SDV reason
    And I click on the button labeled "Confirm reopen"
    Then I should see the SDV form status "SDV in progress"

    # VERIFY - SDV actions are recorded in the project Logging
    Given I click on the link labeled "Logging"
    Then I should see "SDV: field verified"
    And I should see "SDV: monitor query raised"
    And I should see "SDV: monitor query responded"
    And I should see "SDV: form marked SDV complete"
    And I should see "SDV: verified field value changed"
    And I should see "SDV: form requires re-verification"
    And I should see "SDV: form reopened"
    And I should see "SDV: plan changed"
    And I logout
